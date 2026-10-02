<?php

declare(strict_types=1);

namespace App;

/**
 * What the machine is doing right now.
 *
 * Deliberately not `final`: a test that wants to know what the advisor does with
 * a 12 GB card should be able to say so, rather than mock an HTTP client and
 * pretend.
 *
 * Every number here is read, never assumed: the card reports its own power limit
 * and default, so the app can say what is actually in force instead of what was
 * configured somewhere. On this machine the card is capped at 280 W against a
 * 420 W default, and the app should be able to tell you that rather than claim
 * credit for it.
 */
class Hardware
{
    private float $gpuReadAt = 0.0;

    /** @var array<string, mixed>|null */
    private ?array $gpuCache = null;

    /** @var array<int, array{idle: float, busy: float}>|null */
    private ?array $cpuPrev = null;

    private bool $hasNvidia = true;

    public function nvidiaSmi(): ?string
    {
        if (!$this->hasNvidia) {
            return null;
        }

        foreach (['/usr/bin/nvidia-smi', '/usr/local/bin/nvidia-smi', '/usr/local/nvidia/bin/nvidia-smi'] as $path) {
            if (\is_executable($path)) {
                return $path;
            }
        }

        // A packaged app should not trust PATH, but a PATH entry is still better
        // than claiming the machine has no card.
        $found = @\shell_exec('command -v nvidia-smi 2>/dev/null');

        if (\is_string($found) && \trim($found) !== '') {
            return \trim($found);
        }

        $this->hasNvidia = false;

        return null;
    }

    /**
     * Temperature, fan, power and memory, cached for a second so that a UI poll
     * and a governor decision in the same tick cost one process, not two.
     *
     * @return array<string, mixed>|null
     */
    public function gpu(float $ttl = 1.0): ?array
    {
        $now = \microtime(true);

        if ($this->gpuCache !== null && $now - $this->gpuReadAt < $ttl) {
            return $this->gpuCache;
        }

        $smi = $this->nvidiaSmi();

        if ($smi === null) {
            return null;
        }

        $fields = 'name,temperature.gpu,fan.speed,power.draw,power.limit,power.default_limit,power.max_limit,'
            . 'utilization.gpu,utilization.memory,memory.used,memory.total,clocks.sm,clocks.max.sm';

        $command = \sprintf('%s --query-gpu=%s --format=csv,noheader,nounits 2>/dev/null', \escapeshellarg($smi), $fields);
        $output = @\shell_exec($command);

        if (!\is_string($output) || \trim($output) === '') {
            return $this->gpuCache;
        }

        $parts = \array_map(static fn (string $v): string => \trim($v), \explode(',', \trim(\explode("\n", \trim($output))[0])));

        if (\count($parts) < 13) {
            return $this->gpuCache;
        }

        $this->gpuCache = [
            'available' => true,
            'name' => $parts[0],
            'temp' => self::number($parts[1]),
            'fan' => self::number($parts[2]),
            'power' => self::number($parts[3]),
            'power_limit' => self::number($parts[4]),
            'power_default' => self::number($parts[5]),
            'power_max' => self::number($parts[6]),
            'util' => self::number($parts[7]),
            'vram_util' => self::number($parts[8]),
            'vram_used' => self::number($parts[9]),
            'vram_total' => self::number($parts[10]),
            'clock' => self::number($parts[11]),
            'clock_max' => self::number($parts[12]),
        ];
        $this->gpuReadAt = $now;

        return $this->gpuCache;
    }

    /**
     * CPU load as a percentage, from the delta between two /proc/stat reads.
     *
     * @return array<string, mixed>
     */
    public function cpu(): array
    {
        $stat = @\file_get_contents('/proc/stat');
        $load = 0.0;

        if (\is_string($stat) && \preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat, $m) === 1) {
            $busy = (float) ($m[1] + $m[2] + $m[3] + $m[6] + $m[7]);
            $idle = (float) ($m[4] + $m[5]);
            $now = ['idle' => $idle, 'busy' => $busy];

            if ($this->cpuPrev !== null) {
                $dIdle = $now['idle'] - $this->cpuPrev['idle'];
                $dBusy = $now['busy'] - $this->cpuPrev['busy'];
                $total = $dIdle + $dBusy;

                if ($total > 0.0) {
                    $load = \round($dBusy / $total * 100.0, 1);
                }
            }

            $this->cpuPrev = $now;
        }

        $loadavg = @\file_get_contents('/proc/loadavg');
        $parts = \is_string($loadavg) ? \explode(' ', $loadavg) : [];

        return [
            'available' => true,
            'load' => $load,
            'loadavg' => isset($parts[0]) ? (float) $parts[0] : null,
            'cores' => \max(1, (int) (\shell_exec('nproc') ?: 1)),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'gpu' => $this->gpu(),
            'cpu' => $this->cpu(),
            'sampled_at' => \date(\DATE_ATOM),
        ];
    }

    private static function number(string $value): ?float
    {
        $value = \trim($value);

        if ($value === '' || \str_contains($value, 'N/A') || $value === '-') {
            return null;
        }

        return (float) $value;
    }
}
