<?php

declare(strict_types=1);

namespace App;

/**
 * Jobs: tasks that run on a clock, through the same governor.
 *
 * A job does not get its own lane. When one comes due it waits for the agent to
 * be free, and the generation it starts is queued behind whatever else is being
 * asked of the model — which is the whole point. A background job that could
 * run alongside a foreground chat is how a quiet machine stops being quiet.
 *
 * Scheduling is deliberately small: every N minutes, or once a day at HH:MM.
 * There is no cron parser here, and saying so is cheaper than pretending.
 */
final class Jobs
{
    public const int MIN_EVERY = 1;

    private const int MAX_JOBS = 50;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $decoded = \json_decode((string) @\file_get_contents($this->path), true);

        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter($decoded, '\is_array'));
    }

    /** @param array<string, mixed> $job */
    public function save(array $job): array
    {
        // Enforced here rather than in the binding, so every caller gets the rule.
        if (\trim((string) ($job['task'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'a job needs something to do'];
        }

        $job['id'] = \is_string($job['id'] ?? null) && $job['id'] !== ''
            ? $job['id']
            : \date('Ymd-His') . '-' . \substr(\md5((string) ($job['name'] ?? 'job')), 0, 4);

        $jobs = $this->all();
        $replaced = false;

        foreach ($jobs as $index => $existing) {
            if (($existing['id'] ?? '') === $job['id']) {
                $jobs[$index] = $job + $existing;
                $replaced = true;
            }
        }

        if (!$replaced) {
            if (\count($jobs) >= self::MAX_JOBS) {
                return ['ok' => false, 'error' => 'there are already ' . self::MAX_JOBS . ' jobs'];
            }

            $jobs[] = $job;
        }

        $this->write($jobs);

        return ['ok' => true, 'job' => $job];
    }

    public function remove(string $id): bool
    {
        $jobs = \array_values(\array_filter($this->all(), static fn (array $job): bool => ($job['id'] ?? '') !== $id));
        $this->write($jobs);

        return true;
    }

    public function toggle(string $id, bool $enabled): bool
    {
        $jobs = $this->all();

        foreach ($jobs as $index => $job) {
            if (($job['id'] ?? '') === $id) {
                $jobs[$index]['enabled'] = $enabled;
            }
        }

        $this->write($jobs);

        return true;
    }

    /**
     * Which jobs want to run now. `run_requested` is a transient flag the window
     * sets, so "Run now" does not have to lie about the clock.
     *
     * @return list<array<string, mixed>>
     */
    public function due(float $now): array
    {
        $due = [];

        foreach ($this->all() as $job) {
            if (($job['enabled'] ?? false) !== true && ($job['run_requested'] ?? false) !== true) {
                continue;
            }

            if (($job['run_requested'] ?? false) === true) {
                $due[] = $job;

                continue;
            }

            $next = $this->nextRun($job, $now);

            if ($next !== null && $next <= $now) {
                $due[] = $job;
            }
        }

        return $due;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function nextRun(array $job, float $now): ?float
    {
        $last = \is_numeric($job['last_run'] ?? null) ? (float) $job['last_run'] : null;

        if (\is_string($job['at'] ?? null) && \preg_match('/^(\d{1,2}):(\d{2})$/', $job['at'], $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];

            $candidate = \mktime($hour, $minute, 0, (int) \date('n', (int) $now), (int) \date('j', (int) $now), (int) \date('Y', (int) $now));

            if ($candidate === false) {
                return null;
            }

            if ($last !== null && $last >= (float) $candidate) {
                $candidate += 86_400;
            } elseif ((float) $candidate < $now - 60.0) {
                $candidate += 86_400;
            }

            return (float) $candidate;
        }

        $every = \max(self::MIN_EVERY, (int) ($job['every_minutes'] ?? 60)) * 60;

        if ($last === null) {
            // Never run: due as soon as the app has been up a moment. The first
            // run is what tells the reader whether the task is worth scheduling.
            return $now;
        }

        return $last + $every;
    }

    public function markRan(string $id, string $state, float $seconds): void
    {
        $jobs = $this->all();

        foreach ($jobs as $index => $job) {
            if (($job['id'] ?? '') === $id) {
                $jobs[$index]['last_run'] = \microtime(true);
                $jobs[$index]['last_state'] = $state;
                $jobs[$index]['last_seconds'] = \round($seconds, 1);
                $jobs[$index]['run_requested'] = false;
            }
        }

        $this->write($jobs);
    }

    public function requestRun(string $id): void
    {
        $jobs = $this->all();

        foreach ($jobs as $index => $job) {
            if (($job['id'] ?? '') === $id) {
                $jobs[$index]['run_requested'] = true;
            }
        }

        $this->write($jobs);
    }

    /** @return array<string, mixed> */
    public function view(float $now): array
    {
        $jobs = [];

        foreach ($this->all() as $job) {
            $next = $this->nextRun($job, $now);

            $jobs[] = [
                'id' => (string) ($job['id'] ?? ''),
                'name' => (string) ($job['name'] ?? ''),
                'task' => (string) ($job['task'] ?? ''),
                'model' => (string) ($job['model'] ?? ''),
                'enabled' => ($job['enabled'] ?? false) === true,
                'at' => (string) ($job['at'] ?? ''),
                'every_minutes' => (int) ($job['every_minutes'] ?? 0),
                'last_run' => \is_numeric($job['last_run'] ?? null) ? (float) $job['last_run'] : null,
                'last_state' => (string) ($job['last_state'] ?? ''),
                'last_seconds' => \is_numeric($job['last_seconds'] ?? null) ? (float) $job['last_seconds'] : null,
                'next_run' => $next,
                'requested' => ($job['run_requested'] ?? false) === true,
            ];
        }

        return ['jobs' => $jobs, 'path' => $this->path, 'now' => $now];
    }

    /** @param list<array<string, mixed>> $jobs */
    private function write(array $jobs): void
    {
        $directory = \dirname($this->path);

        if (!\is_dir($directory)) {
            @\mkdir($directory, 0o755, true);
        }

        $json = \json_encode(\array_values($jobs), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            @\file_put_contents($this->path, $json . "\n");
        }
    }
}
