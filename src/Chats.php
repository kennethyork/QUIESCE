<?php

declare(strict_types=1);

namespace App;

/**
 * Conversations that survive the window.
 *
 * One JSON file per conversation, in the reader's own config directory, named so
 * the folder sorts by time and readable with `cat`. The title is the first thing
 * the reader asked for, because that is what they will recognise it by — a
 * conversation called "3 August 14:22" is a conversation nobody can find again.
 */
final class Chats
{
    public const int KEEP = 200;

    public const int TITLE = 60;

    public function __construct(private readonly string $directory) {}

    public function directory(): string
    {
        if (!\is_dir($this->directory)) {
            @\mkdir($this->directory, 0o755, true);
        }

        return $this->directory;
    }

    /** @return list<array<string, mixed>> newest first */
    public function list(int $limit = 40): array
    {
        $files = \glob($this->directory() . '/*.json') ?: [];

        $chats = [];

        foreach ($files as $file) {
            $decoded = \json_decode((string) @\file_get_contents($file), true);

            if (!\is_array($decoded)) {
                continue;
            }

            $chats[] = [
                'id' => (string) ($decoded['id'] ?? \basename($file, '.json')),
                'title' => (string) ($decoded['title'] ?? 'untitled'),
                'updated' => (float) ($decoded['updated'] ?? 0),
                'turns' => \count($decoded['messages'] ?? []),
                'tokens' => (int) ($decoded['tokens'] ?? 0),
            ];
        }

        \usort($chats, static fn (array $a, array $b): int => $b['updated'] <=> $a['updated']);

        return \array_slice($chats, 0, $limit);
    }

    public function create(string $title = ''): string
    {
        $id = \date('Y-m-d\TH-i-s') . '-' . \bin2hex(\random_bytes(2));

        $this->write($id, [
            'id' => $id,
            'title' => $title === '' ? 'new conversation' : $title,
            'created' => \microtime(true),
            'updated' => \microtime(true),
            'tokens' => 0,
            'messages' => [],
        ]);

        return $id;
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

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    public function save(string $id, array $messages, int $tokens = 0): array
    {
        $record = $this->load($id) ?? ['id' => $id, 'created' => \microtime(true), 'title' => 'new conversation'];

        $record['messages'] = \array_values($messages);
        $record['tokens'] = $tokens;
        $record['updated'] = \microtime(true);

        if (($record['title'] ?? '') === '' || $record['title'] === 'new conversation') {
            $record['title'] = self::titleFrom($messages);
        }

        $this->write($id, $record);

        return $record;
    }

    /**
     * Find a conversation by what is in it, not only by what it is called.
     *
     * @return list<array{id: string, title: string, updated: float, where: string, snippet: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = \trim($query);

        if (\mb_strlen($query) < 2) {
            return [];
        }

        $needle = \mb_strtolower($query);
        $matches = [];

        foreach (\glob($this->directory() . '/*.json') ?: [] as $file) {
            $decoded = \json_decode((string) @\file_get_contents($file), true);

            if (!\is_array($decoded)) {
                continue;
            }

            $title = (string) ($decoded['title'] ?? '');
            $where = '';
            $snippet = '';

            if (\str_contains(\mb_strtolower($title), $needle)) {
                $where = 'title';
                $snippet = $title;
            } else {
                foreach (\is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [] as $message) {
                    $content = (string) ($message['content'] ?? '');
                    $at = \mb_strpos(\mb_strtolower($content), $needle);

                    if ($at === false) {
                        continue;
                    }

                    $where = ($message['role'] ?? '') === 'user' ? 'your question' : 'the answer';
                    $snippet = '… ' . \trim(\mb_substr($content, \max(0, $at - 60), 140)) . ' …';

                    break;
                }
            }

            if ($where === '') {
                continue;
            }

            $matches[] = [
                'id' => (string) ($decoded['id'] ?? \basename($file, '.json')),
                'title' => $title === '' ? 'untitled' : $title,
                'updated' => (float) ($decoded['updated'] ?? 0),
                'where' => $where,
                'snippet' => $snippet,
            ];
        }

        \usort($matches, static fn (array $a, array $b): int => $b['updated'] <=> $a['updated']);

        return \array_slice($matches, 0, $limit);
    }

    public function rename(string $id, string $title): void
    {
        $record = $this->load($id);

        if ($record === null) {
            return;
        }

        $record['title'] = \trim($title) === '' ? 'untitled' : \substr(\trim($title), 0, self::TITLE);
        $this->write($id, $record);
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

    /**
     * The first question is the title: it is what a reader recognises a
     * conversation by when they come back to it.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function titleFrom(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }

            $text = \trim((string) ($message['content'] ?? ''));

            if ($text === '') {
                continue;
            }

            // Strip the attachment block an inlined file leaves behind.
            $hash = \strpos($text, "\n\n--- ");

            if ($hash !== false) {
                $text = \substr($text, 0, $hash);
            }

            $text = \preg_replace('/\s+/', ' ', $text) ?? $text;

            return $text === '' ? 'new conversation' : \substr($text, 0, self::TITLE);
        }

        return 'new conversation';
    }

    /** @param array<string, mixed> $record */
    private function write(string $id, array $record): void
    {
        $json = \json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            @\file_put_contents($this->path($id), $json . "\n");
        }

        $this->prune();
    }

    private function prune(): void
    {
        $files = \glob($this->directory() . '/*.json') ?: [];

        if (\count($files) <= self::KEEP) {
            return;
        }

        \usort($files, static fn (string $a, string $b): int => (int) @\filemtime($a) <=> (int) @\filemtime($b));

        foreach (\array_slice($files, 0, \count($files) - self::KEEP) as $old) {
            @\unlink($old);
        }
    }
}
