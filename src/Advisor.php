<?php

declare(strict_types=1);

namespace App;

/**
 * What will actually run on this card, and at what context.
 *
 * The arithmetic is the part everyone gets wrong by guessing: a model's file size
 * is only half of what it occupies. The other half is the KV cache, which grows
 * with context and is what turns "the model fits" into "it spilled to the CPU and
 * took four times as long".
 *
 *     KV bytes/token = 2 (K and V) × layers × kv_heads × head_dim × 2 bytes
 *
 * Every number comes from the model's own metadata (`/api/show`) and the card's
 * own report (`nvidia-smi`) — nothing is a table of assumptions about what a 30B
 * usually needs.
 */
final class Advisor
{
    /** CUDA context, driver, and the runtime's own buffers. */
    public const int OVERHEAD = 900 * 1_048_576;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    private float $cachedAt = 0.0;

    public function __construct(
        private readonly ?Ollama $ollama = null,
        private readonly ?Hardware $hardware = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed>|null $modelInfo the model's own metadata, as `/api/show`
     *        returns it; fetched when not supplied, so the arithmetic can be tested
     *        without an engine running
     */
    public function fit(string $model, int $sizeOnDisk, ?array $modelInfo = null): array
    {
        $key = $model . '|' . $sizeOnDisk;

        // The window polls once a second; asking the engine what a model is made
        // of that often would be silly.
        if (isset($this->cache[$key]) && \microtime(true) - $this->cachedAt < 60.0) {
            return $this->cache[$key];
        }

        if (\microtime(true) - $this->cachedAt >= 60.0) {
            $this->cache = [];
            $this->cachedAt = \microtime(true);
        }

        $vram = $this->vram();

        $info = $modelInfo ?? [];

        if ($modelInfo === null && $this->ollama !== null) {
            $shown = $this->ollama->show($model);
            $info = \is_array($shown['model_info'] ?? null) ? $shown['model_info'] : [];
        }

        $architecture = $this->architecture($info);

        $layers = $this->number($info, [$architecture . '.block_count', 'general.block_count']);
        $heads = $this->number($info, [$architecture . '.attention.head_count']);
        $kvHeads = $this->number($info, [$architecture . '.attention.head_count_kv']) ?? $heads;
        $embedding = $this->number($info, [$architecture . '.embedding_length']);
        $headDim = $this->number($info, [$architecture . '.attention.key_length'])
            ?? ($heads !== null && $heads > 0 && $embedding !== null ? (int) ($embedding / $heads) : null);

        $trained = $this->contextLength($info);

        $perToken = null;

        if ($layers !== null && $kvHeads !== null && $headDim !== null) {
            $perToken = 2 * $layers * $kvHeads * $headDim * 2;
        }

        $kvPerThousand = $perToken === null ? null : $perToken * 1_000;

        $free = $vram === null ? null : $vram - $sizeOnDisk - self::OVERHEAD;
        $maxContext = null;

        if ($perToken !== null && $free !== null && $perToken > 0) {
            $maxContext = (int) \floor($free / $perToken);

            if ($maxContext < 1_024) {
                $maxContext = 0;
            }
        }

        $verdict = 'unknown';

        if ($free !== null) {
            if ($free <= 0) {
                $verdict = 'does not fit: ' . self::gb($sizeOnDisk) . ' of weights against ' . self::gb($vram)
                    . ' of video memory — it would run on the CPU';
            } elseif ($maxContext === 0) {
                $verdict = 'fits the weights, but not enough room left for a working context';
            } elseif ($trained !== null && $maxContext >= $trained) {
                $verdict = 'fits at its full ' . \number_format($trained) . ' tokens';
            } else {
                $verdict = 'fits up to about ' . \number_format((int) $maxContext) . ' tokens of context';
            }
        }

        return $this->cache[$key] = [
            'model' => $model,
            'weights' => $sizeOnDisk,
            'vram' => $vram,
            'architecture' => $architecture,
            'layers' => $layers,
            'kv_heads' => $kvHeads,
            'head_dim' => $headDim,
            'kv_per_1k' => $kvPerThousand,
            'trained_context' => $trained,
            'headroom' => $free,
            'max_context' => $maxContext,
            'verdict' => $verdict,
            'exact' => $perToken !== null,
        ];
    }

    /**
     * Every installed model, judged, plus a recommendation for the profile in force.
     *
     * @param list<array<string, mixed>> $models from Ollama::models()
     *
     * @return array<string, mixed>
     */
    public function advise(array $models, Governor $governor, array $loaded = [], ?callable $metadata = null): array
    {
        $profile = $governor->profile();
        $judged = [];
        $resident = 0;

        foreach ($loaded as $model) {
            $resident += (int) ($model['vram'] ?? 0);
        }

        foreach ($models as $model) {
            $name = (string) ($model['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $fit = $this->fit($name, (int) ($model['size'] ?? 0), $metadata === null ? null : $metadata($name));
            $wanted = (int) $profile['num_ctx'];

            $fit['fits_profile'] = $fit['max_context'] === null
                ? null
                : ($fit['max_context'] === 0 ? false : $fit['max_context'] >= $wanted);
            $fit['profile_context'] = $wanted;
            $fit['loaded'] = false;

            foreach ($loaded as $entry) {
                if (($entry['name'] ?? '') === $name) {
                    $fit['loaded'] = true;
                }
            }

            $judged[] = $fit;
        }

        \usort($judged, static function (array $a, array $b): int {
            $aFits = ($a['fits_profile'] ?? false) ? 0 : 1;
            $bFits = ($b['fits_profile'] ?? false) ? 0 : 1;

            return $aFits === $bFits ? $b['weights'] <=> $a['weights'] : $aFits <=> $bFits;
        });

        $recommendation = null;

        foreach ($judged as $fit) {
            if (($fit['fits_profile'] ?? false) === true) {
                $recommendation = $fit['model'];

                break;
            }
        }

        return [
            'vram' => $this->vram(),
            'reserved' => $resident,
            'profile' => $profile['id'],
            'profile_context' => (int) $profile['num_ctx'],
            'models' => $judged,
            'recommendation' => $recommendation,
            'note' => $this->vram() === null
                ? 'no NVIDIA card answered nvidia-smi, so nothing can be sized against video memory'
                : 'KV cache per 1,000 tokens is computed from the model\'s own layer and head counts',
        ];
    }

    private function vram(): ?int
    {
        $gpu = $this->hardware?->gpu() ?? [];

        return \is_numeric($gpu['vram_total'] ?? null) ? (int) $gpu['vram_total'] * 1_048_576 : null;
    }

    /** @param array<string, mixed> $info */
    private function architecture(array $info): string
    {
        $general = $info['general.architecture'] ?? null;

        if (\is_string($general) && $general !== '') {
            return $general;
        }

        foreach ($info as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.block_count')) {
                return \substr($key, 0, -\strlen('.block_count'));
            }
        }

        return 'general';
    }

    /**
     * @param array<string, mixed> $info
     * @param list<string> $keys
     */
    private function number(array $info, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($info[$key]) && \is_numeric($info[$key])) {
                return (int) $info[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $info */
    private function contextLength(array $info): ?int
    {
        $longest = null;

        foreach ($info as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.context_length') && \is_numeric($value)) {
                $longest = \max((int) $longest, (int) $value);
            }
        }

        return $longest;
    }

    private static function gb(?int $bytes): string
    {
        return \number_format(($bytes ?? 0) / 1_073_741_824, 1) . ' GB';
    }
}
