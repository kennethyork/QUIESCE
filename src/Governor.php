<?php

declare(strict_types=1);

namespace App;

/**
 * The quiet governor.
 *
 * Fans are driven by heat, heat is driven by sustained power draw, and sustained
 * power draw is driven by *duty cycle* — how much of the time the card is busy.
 * A single answer for twenty seconds is invisible in a fan curve. An agent
 * looping tool calls for twenty minutes is not. So the governor works on time,
 * in four moves, none of which require root:
 *
 *   1. Serialise. One generation at a time, always. Agents that fan out into
 *      parallel calls get queued instead of stacking five CUDA streams on a card
 *      that then runs at its power limit for as long as the fan keeps catching up.
 *   2. Duty-cycle. Read the generation in bursts (on for N ms, off for M ms).
 *      While holding, the socket is not read: the server's buffer fills, the
 *      model blocks on its next write, and the GPU goes idle for that stretch.
 *      This is the knob that actually decides the average wattage.
 *   3. Ceilings. Above the temperature ceiling everything holds until the card
 *      has fallen back to the floor temperature. Hysteresis, so it cannot chatter.
 *   4. Budget per window. If the last minute was busy for longer than the
 *      profile allows, the next request waits. This is what stops a long agent
 *      run from quietly becoming a space heater.
 *
 * Every decision is written to a log the UI shows, with the number that caused it.
 */
final class Governor
{
    /**
     * Profiles are honest trade-offs, not presets for a screenshot. The figures
     * are the ceiling temperature, the floor it must fall back to, the duty
     * window, and what is passed through to the model.
     *
     * @var array<string, array<string, mixed>>
     */
    public const array PRESETS = [
        'whisper' => [
            'label' => 'Whisper',
            'note' => 'barely audible; a 4k context, a short leash',
            'ceiling' => 66.0,
            'floor' => 58.0,
            'on_ms' => 2_000,
            'off_ms' => 1_800,
            'max_tps' => 12,
            'minutes' => 20.0,
            'max_duty' => 0.45,
            'max_request_seconds' => 25.0,
            'num_ctx' => 4_096,
            'num_predict' => 512,
            'num_thread' => 4,
            'keep_alive' => '2m',
        ],
        'steady' => [
            'label' => 'Steady',
            'note' => 'the default: audible under load, never a jet',
            'ceiling' => 74.0,
            'floor' => 66.0,
            'on_ms' => 4_000,
            'off_ms' => 900,
            'max_tps' => 30,
            'minutes' => 20.0,
            'max_duty' => 0.7,
            'max_request_seconds' => 90.0,
            'num_ctx' => 8_192,
            'num_predict' => 1_024,
            'num_thread' => 6,
            'keep_alive' => '5m',
        ],
        'fast' => [
            'label' => 'Fast',
            'note' => 'uncapped: the model sets the pace, the fans follow it',
            'ceiling' => 84.0,
            'floor' => 76.0,
            'on_ms' => 0,
            'off_ms' => 0,
            'max_tps' => 0,
            'minutes' => 20.0,
            'max_duty' => 1.0,
            'max_request_seconds' => 0.0,
            'num_ctx' => 16_384,
            'num_predict' => 2_048,
            'num_thread' => 0,
            'keep_alive' => '15m',
        ],
    ];

    /**
     * The hard ceiling on one answer's tokens, whatever the profile says.
     *
     * Reasoning models count their thinking against the same budget as their
     * answer, so a cap that is right for a plain model truncates a thinking one
     * into silence — measured on qwen3:30b-a3b: under Steady (1,024) it spent the
     * lot thinking and emitted nothing at all, while under Fast (2,048) it
     * finished the task in four steps.
     */
    public const int MAX_PREDICT = 4_096;

    private string $preset = 'steady';

    /** @var list<array{t: float, level: string, message: string}> */
    private array $log = [];

    /** @var list<array{t: float, seconds: float}> */
    private array $busy = [];

    /** Wall-clock start of the current on/off window. */
    private float $windowAt = 0.0;

    /** Set while a temperature hold is in force. */
    private ?float $holdUntil = null;

    private bool $holding = false;

    private string $reason = 'idle';

    /** @var array<string, mixed>|null */
    private ?array $gpu = null;

    public function preset(): string
    {
        return $this->preset;
    }

    public function usePreset(string $preset): bool
    {
        if (!isset(self::PRESETS[$preset])) {
            return false;
        }

        $this->preset = $preset;
        $this->windowAt = 0.0;
        $this->holdUntil = null;
        $this->holding = false;
        $this->note('profile', \sprintf('%s profile: %s', self::PRESETS[$preset]['label'], self::PRESETS[$preset]['note']));

        return true;
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        return self::PRESETS[$this->preset];
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $preset = $this->current();
        $preset['id'] = $this->preset;

        return $preset;
    }

    public function ceiling(): float
    {
        return (float) $this->current()['ceiling'];
    }

    /**
     * Model options for the profile, in Ollama's own vocabulary.
     *
     * num_thread is capped by the profile, num_ctx and num_predict are ceilings
     * rather than overrides: a client that asked for something smaller keeps it.
     *
     * @param array<string, mixed> $asked
     *
     * @return array<string, mixed>
     */
    public function options(array $asked = [], int $multiplier = 1): array
    {
        $preset = $this->current();
        $threads = (int) $preset['num_thread'];

        if ($threads > 0) {
            $asked['num_thread'] = \min((int) ($asked['num_thread'] ?? $threads), $threads);
        }

        $cap = (int) $preset['num_predict'];
        $room = \min($cap * \max(1, $multiplier), self::MAX_PREDICT);

        $asked['num_ctx'] = \min((int) ($asked['num_ctx'] ?? $preset['num_ctx']), (int) $preset['num_ctx']);

        // The default is the *room*, not the base cap. Otherwise the widening is
        // available only to a caller that already knew to ask for it, and a
        // reasoning model asking for nothing gets exactly the budget that
        // silences it — which is what the 30B kept doing here.
        $asked['num_predict'] = \min((int) ($asked['num_predict'] ?? $room), $room);

        return $asked;
    }

    public function keepAlive(): string
    {
        return (string) $this->current()['keep_alive'];
    }

    /**
     * A ceiling on tokens per second, where the profile has one.
     *
     * This is the most direct lever on sustained wattage there is: a card
     * generating 90 tokens/s holds its boost clock and its fan curve; the same
     * card at 12 tokens/s spends most of its time between tokens. It costs
     * speed and returns quiet, which is the trade the reader is choosing.
     */
    public function tokensPerSecond(): int
    {
        return (int) ($this->current()['max_tps'] ?? 0);
    }

    /** Called by the caller while a generation is in flight, once per tick. */
    public function markBusy(float $seconds): void
    {
        $this->busy[] = ['t' => \microtime(true), 'seconds' => $seconds];
        $this->prune();
    }

    /**
     * Is a generation allowed to make progress this tick?
     *
     * @return array{allow: bool, reason: string, retry_ms: int}
     */
    public function gate(?array $gpu): array
    {
        $this->gpu = $gpu;
        $now = \microtime(true);
        $preset = $this->current();
        $temp = \is_array($gpu) ? ($gpu['temp'] ?? null) : null;

        // 3. temperature ceiling, with hysteresis
        if (\is_float($temp) || \is_int($temp)) {
            $temp = (float) $temp;

            if ($this->holdUntil !== null) {
                if ($temp > (float) $preset['floor']) {
                    $this->holding = true;
                    $this->reason = \sprintf('cooling at %.0f °C (floor %.0f °C)', $temp, (float) $preset['floor']);

                    return ['allow' => false, 'reason' => $this->reason, 'retry_ms' => 500];
                }

                $this->note('resume', \sprintf('resumed at %.0f °C', $temp));
                $this->holdUntil = null;
                $this->holding = false;
                $this->windowAt = $now;
            } elseif ($temp >= (float) $preset['ceiling']) {
                $this->holdUntil = $now;
                $this->holding = true;
                $this->reason = \sprintf('%.0f °C reached the %.0f °C ceiling', $temp, (float) $preset['ceiling']);
                $this->note('hold', $this->reason . ' — holding until the card is back to ' . \number_format((float) $preset['floor'], 0) . ' °C');

                return ['allow' => false, 'reason' => $this->reason, 'retry_ms' => 500];
            }
        }

        // 4. budget over the window
        $window = $this->duty();
        $budget = (float) $preset['max_duty'];

        if ($budget < 1.0 && $window > $budget && $this->busy !== []) {
            $this->reason = \sprintf('%.0f%% of the last %.0f minutes busy (budget %.0f%%)', $window * 100, $preset['minutes'], $budget * 100);

            return ['allow' => false, 'reason' => $this->reason, 'retry_ms' => 1_000];
        }

        // 2. duty cycle within a generation
        $onMs = (float) $preset['on_ms'];
        $offMs = (float) $preset['off_ms'];

        if ($onMs > 0.0 && $offMs > 0.0) {
            if ($this->windowAt === 0.0) {
                $this->windowAt = $now;
            }

            $elapsed = ($now - $this->windowAt) * 1_000.0;
            $period = $onMs + $offMs;

            if ($elapsed >= $period) {
                $this->windowAt = $now;
                $elapsed = 0.0;
            }

            if ($elapsed >= $onMs) {
                $this->reason = \sprintf('in the off %.1fs of a %.0f%% duty cycle', $offMs / 1_000, $onMs / $period * 100);

                return ['allow' => false, 'reason' => $this->reason, 'retry_ms' => (int) \max(50.0, $period - $elapsed)];
            }
        }

        $this->holding = false;
        $this->reason = 'going';

        return ['allow' => true, 'reason' => 'going', 'retry_ms' => 0];
    }

    /** Fraction of the last window spent generating. */
    public function duty(): float
    {
        $this->prune();

        if ($this->busy === []) {
            return 0.0;
        }

        $seconds = (float) $this->current()['minutes'] * 60.0;
        $sum = 0.0;

        foreach ($this->busy as $sample) {
            $sum += $sample['seconds'];
        }

        return \min(1.0, $sum / \max(1.0, $seconds));
    }

    /** The length of the window in seconds that duty is measured over. */
    public function dutyWindowSeconds(): float
    {
        return (float) $this->current()['minutes'] * 60.0;
    }

    /**
     * A request that has run past its profile's limit is paused for a cooldown,
     * which is the honest way to keep a long agent run from becoming a runaway.
     *
     * @return array{allow: bool, reason: string}
     */
    public function pastRequestBudget(float $seconds): array
    {
        $limit = (float) $this->current()['max_request_seconds'];

        if ($limit <= 0.0 || $seconds < $limit) {
            return ['allow' => true, 'reason' => ''];
        }

        $this->reason = \sprintf('this request has been generating for %.0fs (limit %.0fs)', $seconds, $limit);

        return ['allow' => false, 'reason' => $this->reason];
    }

    public function holding(): bool
    {
        return $this->holding;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function note(string $level, string $message): void
    {
        $this->log[] = ['t' => \microtime(true), 'level' => $level, 'message' => $message];

        if (\count($this->log) > 300) {
            $this->log = \array_slice($this->log, -300);
        }
    }

    /** @return list<array{t: float, level: string, message: string}> */
    public function log(): array
    {
        return \array_reverse($this->log);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'profile' => $this->profile(),
            'duty' => \round($this->duty(), 3),
            'duty_window_seconds' => $this->dutyWindowSeconds(),
            'holding' => $this->holding,
            'reason' => $this->reason,
            'window_remaining_ms' => $this->windowAt === 0.0
                ? 0
                : \max(0, (int) (((float) $this->current()['on_ms'] / 1_000.0) - (\microtime(true) - $this->windowAt)) * 1_000),
            'gpu_temp' => \is_array($this->gpu) ? ($this->gpu['temp'] ?? null) : null,
        ];
    }

    private function prune(): void
    {
        $cutoff = \microtime(true) - $this->dutyWindowSeconds();

        $this->busy = \array_values(\array_filter(
            $this->busy,
            static fn (array $sample): bool => $sample['t'] >= $cutoff,
        ));
    }
}
