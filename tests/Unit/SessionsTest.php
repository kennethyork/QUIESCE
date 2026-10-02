<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sessions;
use PHPUnit\Framework\TestCase;

/**
 * Transcripts have to survive the process that wrote them, so these tests write
 * to a real directory and read the files back.
 */
final class SessionsTest extends TestCase
{
    private string $directory;

    private Sessions $sessions;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/quiesce-sessions-' . \bin2hex(\random_bytes(4));
        $this->sessions = new Sessions($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*.json') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);
    }

    public function testARecordedSessionCanBeReadBack(): void
    {
        $id = $this->sessions->start('Summarise the notes in notes.md', 'qwen3:8b', '/tmp/folder', 'steady');

        self::assertFileExists($this->sessions->path($id));
        self::assertStringContainsString('summarise-the-notes', $id, 'the id still means something');

        $this->sessions->finish($id, [
            'state' => 'done',
            'seconds' => 5.6,
            'tokens' => 25,
            'answer' => 'I wrote summary.md.',
            'steps' => [
                ['tool' => 'read_file', 'arguments' => ['path' => 'notes.md']],
                ['tool' => 'write_file', 'arguments' => ['path' => 'summary.md']],
            ],
        ], [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'Summarise the notes in notes.md'],
            ['role' => 'assistant', 'content' => 'I wrote summary.md.'],
        ]);

        $record = $this->sessions->load($id);

        self::assertNotNull($record);
        self::assertSame('done', $record['state']);
        self::assertSame(5.6, $record['seconds']);
        self::assertSame(['summary.md'], $record['files'], 'the files it wrote are recorded');
        self::assertCount(3, $this->sessions->messages($id));
    }

    public function testTheListIsNewestFirstAndSaysWhatCanBeResumed(): void
    {
        $first = $this->sessions->start('first task', 'qwen3:8b', '/tmp', 'steady');
        \usleep(1_100_000);
        $second = $this->sessions->start('second task', 'qwen3:8b', '/tmp', 'steady');

        $this->sessions->finish($second, [
            'state' => 'done',
            'seconds' => 1.0,
            'steps' => [],
            'answer' => 'done',
        ], [
            ['role' => 'system', 'content' => 'x'],
            ['role' => 'user', 'content' => 'second task'],
        ]);

        $list = $this->sessions->list();

        self::assertSame($second, $list[0]['id']);
        self::assertTrue($list[0]['resumable'], 'a session with a history can be continued');
        self::assertSame($first, $list[1]['id']);
        self::assertFalse($list[1]['resumable'], 'one that never finished has no history to continue');
    }

    public function testASessionCanBeRemoved(): void
    {
        $id = $this->sessions->start('throwaway', 'qwen3:8b', '/tmp', 'steady');

        self::assertTrue($this->sessions->remove($id));
        self::assertNull($this->sessions->load($id));
        self::assertFileDoesNotExist($this->sessions->path($id));
    }

    public function testAnIdCannotEscapeTheDirectory(): void
    {
        $path = $this->sessions->path('../../etc/passwd');

        self::assertSame($this->directory, \dirname($path), 'a crafted id cannot move the file out of the folder');
        self::assertStringNotContainsString('/', \basename($path));
    }
}
