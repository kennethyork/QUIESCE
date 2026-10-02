<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Checkpoints;
use PHPUnit\Framework\TestCase;

/**
 * Undo, tested against a real folder on disk — because the whole point of it is
 * what is actually in the reader's files afterwards, and a mocked filesystem would
 * be testing the mock.
 */
final class CheckpointsTest extends TestCase
{
    private string $base;
    private string $root;
    private string $journal;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/quiesce-checkpoints-' . \bin2hex(\random_bytes(4));
        $this->root = $this->base . '/workspace';
        $this->journal = $this->base . '/checkpoints';

        \mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testItPutsAFileBackAsItWas(): void
    {
        $file = $this->root . '/notes.md';
        \file_put_contents($file, "one\ntwo\n");

        $checkpoints = new Checkpoints($this->journal);
        $remembered = $checkpoints->remember($this->root, $file, 'edit_file');

        self::assertTrue($remembered['ok']);

        // What the agent did to it, wrongly.
        \file_put_contents($file, "one\nWRONG\n");

        $undone = $checkpoints->undoLast();

        self::assertTrue($undone['ok']);
        self::assertSame("one\ntwo\n", \file_get_contents($file), 'the file is back as it was');
        self::assertSame(0, $checkpoints->count(), 'and the checkpoint is spent');
    }

    public function testACreatedFileIsRemovedRatherThanRestored(): void
    {
        $file = $this->root . '/summary.md';

        $checkpoints = new Checkpoints($this->journal);
        $checkpoints->remember($this->root, $file, 'write_file');
        \file_put_contents($file, 'the agent wrote this');

        $undone = $checkpoints->undoLast();

        self::assertTrue($undone['ok']);
        self::assertFileDoesNotExist($file);
        self::assertStringContainsString('removed', $undone['output'], 'and it says it removed it rather than restored it');
    }

    public function testUndoIsOneStepAtATimeNewestFirst(): void
    {
        $file = $this->root . '/notes.md';
        \file_put_contents($file, 'first');

        $checkpoints = new Checkpoints($this->journal);

        $checkpoints->remember($this->root, $file, 'edit_file');
        \file_put_contents($file, 'second');
        $checkpoints->remember($this->root, $file, 'edit_file');
        \file_put_contents($file, 'third');

        self::assertSame(2, $checkpoints->count());

        $checkpoints->undoLast();
        self::assertSame('second', \file_get_contents($file), 'one step back');

        $checkpoints->undoLast();
        self::assertSame('first', \file_get_contents($file), 'and one more');
    }

    public function testAnEmptyJournalRefusesRatherThanGuessing(): void
    {
        $checkpoints = new Checkpoints($this->journal);

        $undone = $checkpoints->undoLast();

        self::assertFalse($undone['ok']);
        self::assertStringContainsString('nothing to undo', $undone['output']);
    }

    public function testItSaysSoWhenAFileIsTooLargeToKeepACopyOf(): void
    {
        $file = $this->root . '/huge.bin';
        \file_put_contents($file, \str_repeat('x', Checkpoints::MAX_BYTES + 1));

        $checkpoints = new Checkpoints($this->journal);
        $remembered = $checkpoints->remember($this->root, $file, 'write_file');

        // Refused the *net*, not the write: the change goes ahead and the app says it
        // cannot be undone, which is the honest version of the same sentence.
        self::assertFalse($remembered['ok']);
        self::assertStringContainsString('cannot be undone', $remembered['note']);
        self::assertSame(0, $checkpoints->count());
    }

    public function testTheJournalIsCappedRatherThanGrowingForEver(): void
    {
        $file = $this->root . '/notes.md';
        $checkpoints = new Checkpoints($this->journal);

        for ($i = 0; $i < Checkpoints::KEEP + 5; $i++) {
            \file_put_contents($file, 'version ' . $i);
            $checkpoints->remember($this->root, $file, 'edit_file');
        }

        self::assertSame(Checkpoints::KEEP, $checkpoints->count(), 'the newest are kept, the oldest dropped');
    }

    public function testAPathThatLeftTheFolderIsNotWrittenTo(): void
    {
        $file = $this->root . '/notes.md';
        \file_put_contents($file, 'the agent wrote this');

        $checkpoints = new Checkpoints($this->journal);
        $checkpoints->remember($this->root, $file, 'write_file');

        // The folder goes away underneath the journal — a moved workspace, or a task that
        // ran yesterday. Undo must refuse rather than write to a path it no longer knows.
        \rename($this->root, $this->base . '/moved');

        $undone = $checkpoints->undoLast();

        self::assertFalse($undone['ok']);
        self::assertStringContainsString('no longer inside the working folder', $undone['output']);
        self::assertSame(1, $checkpoints->count(), 'and the entry is kept rather than thrown away');
    }

    private function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            @\rmdir($path);

            return;
        }

        @\unlink($path);
    }
}
