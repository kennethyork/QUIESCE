<?php

declare(strict_types=1);

namespace App;

/**
 * Task transcripts that survive the window.
 *
 * One JSON file per task in `~/.config/quiesce/sessions`, named so the folder
 * sorts by time. Deliberately readable: `cat`, `grep` and `jq` all work on it,
 * because a transcript you cannot read outside the app is a transcript you
 * cannot trust. The full message history is kept alongside the step list, which
 * is what makes a session resumable rather than just a log.
 */
final class Sessions
{
    public const int KEEP = 200;

    public function __construct(private readonly string $directory) {}

    public function directory(): string
    {
        if (!\is_dir($this->directory)) {
            @\mkdir($this->directory, 0o755, true);
        }

        return $this->directory;
    }

    /**
     * Begin a session. The id is the timestamp plus a slug of the task, so the
     * folder sorts chronologically and a filename still means something.
     */
    public function start(string $task, string $model, ?string $workspace, string $profile): string
    {
        $id = \date('Y-m-d\TH-i-s') . '-' . self::slug($task);

        $this->write($id, [
            'id' => $id,
            'task' => $task,
            'model' => $model,
            'workspace' => $workspace,
            'profile' => $profile,
            'started' => \microtime(true),
            'finished' => null,
            'state' => 'running',
            'seconds' => null,
            'tokens' => 0,
            'steps' => [],
            'answer' => '',
            'messages' => [],
            'job' => null,
            'files' => [],
        ]);

        return $id;
    }

    /** @param array<string, mixed> $state the agent's own state snapshot */
    public function finish(string $id, array $state, array $messages, ?string $job = null): void
    {
        $record = $this->load($id) ?? ['id' => $id, 'task' => (string) ($state['task'] ?? '')];

        $files = [];

        foreach ($state['steps'] ?? [] as $step) {
            if (($step['tool'] ?? '') === 'write_file' && isset($step['arguments']['path'])) {
                $files[] = (string) $step['arguments']['path'];
            }
        }

        $record['finished'] = \microtime(true);
        $record['state'] = (string) ($state['state'] ?? 'done');
        $record['seconds'] = (float) ($state['seconds'] ?? 0);
        $record['tokens'] = (int) ($state['tokens'] ?? 0);
        $record['steps'] = $state['steps'] ?? [];
        $record['answer'] = (string) ($state['answer'] ?? '');
        $record['error'] = (string) ($state['error'] ?? '');
        $record['messages'] = $messages;
        $record['files'] = \array_values(\array_unique($files));
        $record['usage'] = $state['usage'] ?? [];

        if ($job !== null) {
            $record['job'] = $job;
        }

        $this->write($id, $record);
        $this->prune();
    }

    /** @return list<array<string, mixed>> newest first */
    public function list(int $limit = 40): array
    {
        $directory = $this->directory();
        $files = \glob($directory . '/*.json') ?: [];
        \rsort($files);

        $sessions = [];

        foreach (\array_slice($files, 0, $limit) as $file) {
            $decoded = \json_decode((string) @\file_get_contents($file), true);

            if (!\is_array($decoded)) {
                continue;
            }

            $sessions[] = [
                'id' => (string) ($decoded['id'] ?? \basename($file, '.json')),
                'task' => (string) ($decoded['task'] ?? ''),
                'model' => (string) ($decoded['model'] ?? ''),
                'state' => (string) ($decoded['state'] ?? ''),
                'started' => (float) ($decoded['started'] ?? 0),
                'seconds' => (float) ($decoded['seconds'] ?? 0),
                'steps' => \count($decoded['steps'] ?? []),
                'resumable' => \is_array($decoded['messages'] ?? null) && ($decoded['messages'] ?? []) !== [],
                'files' => $decoded['files'] ?? [],
                'job' => $decoded['job'] ?? null,
            ];
        }

        return $sessions;
    }

    /** @return array<string, mixed>|null */
    public function load(string $id): ?array
    {
        $path = $this->path($id);

        if (!\is_file($path)) {
            return null;
        }

        $decoded = \json_decode((string) @\file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string, mixed>> the message history, for continuing */
    public function messages(string $id): array
    {
        $record = $this->load($id);
        $messages = $record['messages'] ?? [];

        return \is_array($messages) ? \array_values($messages) : [];
    }

    public function remove(string $id): bool
    {
        $path = $this->path($id);

        return \is_file($path) && @\unlink($path);
    }

    public function path(string $id): string
    {
        return $this->directory() . '/' . \preg_replace('/[^A-Za-z0-9._-]/', '-', $id) . '.json';
    }

    private function write(string $id, array $record): void
    {
        $json = \json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            @\file_put_contents($this->path($id), $json . "\n");
        }
    }

    private function prune(): void
    {
        $files = \glob($this->directory() . '/*.json') ?: [];

        if (\count($files) <= self::KEEP) {
            return;
        }

        \rsort($files);

        foreach (\array_slice($files, self::KEEP) as $old) {
            @\unlink($old);
        }
    }

    private static function slug(string $task): string
    {
        $slug = \strtolower(\preg_replace('/[^A-Za-z0-9 ]+/', ' ', $task) ?? '');
        $slug = \trim(\preg_replace('/\s+/', '-', $slug) ?? '', '-');

        return $slug === '' ? 'task' : \substr($slug, 0, 40);
    }
}
