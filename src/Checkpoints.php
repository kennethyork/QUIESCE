<?php

declare(strict_types=1);

namespace App;

/**
 * Undo, for the folder an agent writes in.
 *
 * The agent already verifies what it writes — `stat()` and a read-back before it
 * claims anything. That catches a write that did not happen. It does not help with a
 * write that *did* happen and was wrong: a file replaced with the model's idea of it,
 * a function "fixed" into three functions. Commands have had a gate for this from the
 * start; writes had nothing. This is that gate's other half, and it is the seatbelt
 * rather than the brake: it does not stop anything, it makes the mistake survivable.
 *
 * How it works, and why it is this simple:
 *
 *   - every write and every edit passes through `Tools`, so there is exactly one
 *     place to stand: before the file is changed, what was there is copied aside;
 *   - the journal is a JSON file in the reader's own config directory, next to the
 *     sessions, so what the agent changed is readable with `cat` and is not a mystery
 *     inside the application;
 *   - undo restores the newest entry, one at a time. Not a rollback of everything
 *     since Tuesday: a reader pressing undo wants the last thing back, and a button
 *     that silently rewrites forty files is a bigger risk than the one it guards;
 *   - a file created by the agent is undone by removing it, and the entry says so
 *     rather than pretending a file was restored;
 *   - the journal is capped, and the cap is stated: forty writes. Older ones are
 *     dropped, and `undoLast()` reports an empty journal rather than guessing.
 *
 * It refuses rather than guesses, in the same voice as everything else here: a file
 * bigger than the cap is *not* checkpointed, and the write still goes ahead with the
 * log saying it is not undoable. Refusing the write itself would be worse — the agent
 * is told what it can and cannot do, and this is not a permission, it is a net.
 */
final class Checkpoints
{
    /** Writes remembered at once. Older entries are pruned rather than growing forever. */
    public const int KEEP = 40;

    /** Above this, a file is not copied aside — the log says the change is not undoable. */
    public const int MAX_BYTES = 1_048_576;

    public function __construct(private readonly string $directory) {}

    /**
     * Copy aside what is about to be replaced.
     *
     * @return array{ok: bool, note: string} `note` is written to the governor log when
     *                                       `ok` is false: the app says what it could
     *                                       not protect instead of implying it did.
     */
    public function remember(string $root, string $absolute, string $tool): array
    {
        $relative = \ltrim(\str_replace($root, '', $absolute), '/');
        $exists = \is_file($absolute);

        if ($exists) {
            $size = @\filesize($absolute);

            if (\is_int($size) && $size > self::MAX_BYTES) {
                return ['ok' => false, 'note' => \sprintf(
                    '%s changed %s, which is larger than %s — not checkpointed, so that change cannot be undone',
                    $tool,
                    $relative,
                    self::size(self::MAX_BYTES),
                )];
            }
        }

        $entries = $this->read();
        $id = \sprintf('%d-%s', (int) \microtime(true) * 1000 + \count($entries) % 1000, \bin2hex(\random_bytes(2)));
        $blob = null;

        if ($exists) {
            $blob = $id . '.bak';

            if (!\is_dir($this->directory . '/blobs') && !@\mkdir($this->directory . '/blobs', 0o700, true)) {
                return ['ok' => false, 'note' => 'could not make ' . $this->directory . '/blobs, so nothing was checkpointed'];
            }

            if (!@\copy($absolute, $this->directory . '/blobs/' . $blob)) {
                return ['ok' => false, 'note' => 'could not copy ' . $relative . ' aside, so that change cannot be undone'];
            }
        }

        $entries[] = [
            'id' => $id,
            't' => \microtime(true),
            'tool' => $tool,
            'root' => $root,
            'path' => $relative,
            'existed' => $exists,
            'blob' => $blob,
        ];

        // Oldest first out. The blobs go with their entries, or the directory grows
        // for ever and the journal starts to be a liability of its own.
        while (\count($entries) > self::KEEP) {
            $gone = \array_shift($entries);

            if (\is_array($gone) && \is_string($gone['blob'] ?? null)) {
                @\unlink($this->directory . '/blobs/' . $gone['blob']);
            }
        }

        $this->write($entries);

        return ['ok' => true, 'note' => \sprintf('%s %s (undoable)', $exists ? 'replaced' : 'created', $relative)];
    }

    /**
     * Put the newest change back, and say what was put back and where.
     *
     * @return array{ok: bool, output: string}
     */
    public function undoLast(): array
    {
        $entries = $this->read();

        while ($entries !== []) {
            $entry = \array_pop($entries);

            if (!\is_array($entry)) {
                continue;
            }

            $root = (string) ($entry['root'] ?? '');
            $relative = (string) ($entry['path'] ?? '');
            $target = $root . '/' . $relative;
            $inside = $root !== '' && \realpath(\dirname($target)) !== false
                && \str_starts_with((string) \realpath(\dirname($target)), (string) \realpath($root));

            if (!$inside) {
                // The folder moved or the path escaped: refusing here is the same rule the
                // file tools live by, and the entry is kept rather than silently dropped.
                $entries[] = $entry;
                $this->write($entries);

                return ['ok' => false, 'output' => \sprintf(
                    'cannot undo %s: %s is no longer inside the working folder it was written in',
                    $relative,
                    $root,
                )];
            }

            $this->write($entries);

            if (($entry['existed'] ?? false) !== true) {
                @\unlink($target);

                return ['ok' => true, 'output' => \sprintf(
                    'undid %s: %s was created by the agent and has been removed',
                    $entry['tool'] ?? 'a write',
                    $relative,
                )];
            }

            $blob = $this->directory . '/blobs/' . (string) ($entry['blob'] ?? '');

            if (!\is_file($blob)) {
                return ['ok' => false, 'output' => 'cannot undo ' . $relative . ': the checkpoint for it is gone'];
            }

            if (!@\copy($blob, $target)) {
                return ['ok' => false, 'output' => 'could not put ' . $relative . ' back'];
            }

            @\unlink($blob);

            return ['ok' => true, 'output' => \sprintf(
                'undid %s: %s is back as it was (%s)',
                $entry['tool'] ?? 'a write',
                $relative,
                self::ago((float) ($entry['t'] ?? 0.0)),
            )];
        }

        return ['ok' => false, 'output' => 'nothing to undo: no write has been checkpointed since the app started'];
    }

    /** How many changes are held, for the window to say. */
    public function count(): int
    {
        return \count($this->read());
    }

    /**
     * The journal, as entries. Anything unreadable is treated as empty — a corrupt
     * journal must not become an exception in the middle of a task, and it must not
     * be silently rewritten either: `read()` leaves the file alone.
     *
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $raw = @\file_get_contents($this->directory . '/checkpoints.json');

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);

        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter($decoded, '\\is_array'));
    }

    /** @param list<array<string, mixed>> $entries */
    private function write(array $entries): void
    {
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o700, true)) {
            return;
        }

        @\file_put_contents(
            $this->directory . '/checkpoints.json',
            \json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1_024 * 1_024
            ? \round($bytes / (1_024 * 1_024), 1) . ' MB'
            : \round($bytes / 1_024) . ' KB';
    }

    private static function ago(float $t): string
    {
        $seconds = \max(0, (int) (\microtime(true) - $t));

        return $seconds < 60 ? $seconds . 's ago' : (int) ($seconds / 60) . ' min ago';
    }
}
