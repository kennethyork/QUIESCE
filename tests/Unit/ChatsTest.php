<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Chats;
use PHPUnit\Framework\TestCase;

/**
 * Conversations on disk: one file each, named so the folder sorts, titled by the
 * first thing the reader asked for — because a conversation called "14:22" is one
 * nobody finds again.
 */
final class ChatsTest extends TestCase
{
    private string $directory;

    private Chats $chats;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/quiesce-chats-' . \bin2hex(\random_bytes(4));
        $this->chats = new Chats($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*.json') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);
    }

    public function testANewConversationIsAReadableFile(): void
    {
        $id = $this->chats->create();

        self::assertFileExists($this->chats->path($id));
        self::assertSame('new conversation', $this->chats->load($id)['title']);

        $raw = (string) \file_get_contents($this->chats->path($id));
        self::assertStringContainsString('"messages"', $raw, 'plain JSON, readable with cat');
    }

    public function testTheFirstQuestionBecomesTheTitle(): void
    {
        $id = $this->chats->create();

        $this->chats->save($id, [
            ['role' => 'user', 'content' => 'What does the whisper profile actually do?'],
            ['role' => 'assistant', 'content' => 'It holds the card at 66 °C.'],
        ]);

        self::assertSame('What does the whisper profile actually do?', $this->chats->load($id)['title']);
    }

    public function testAnInlinedAttachmentIsNotPartOfTheTitle(): void
    {
        $title = Chats::titleFrom([
            ['role' => 'user', 'content' => "What does this say?\n\n--- notes.md ---\n# Notes\n\nsome text"],
        ]);

        self::assertSame('What does this say?', $title);
    }

    public function testALongTitleIsShortened(): void
    {
        $title = Chats::titleFrom([['role' => 'user', 'content' => \str_repeat('word ', 40)]]);

        self::assertLessThanOrEqual(Chats::TITLE, \strlen($title));
    }

    public function testTheListIsNewestFirst(): void
    {
        $first = $this->chats->create();
        \usleep(1_100_000);
        $second = $this->chats->create();

        $this->chats->save($first, [['role' => 'user', 'content' => 'older']]);
        \usleep(1_100_000);
        $this->chats->save($second, [['role' => 'user', 'content' => 'newer']]);

        $list = $this->chats->list();

        self::assertSame($second, $list[0]['id']);
        self::assertSame('newer', $list[0]['title']);
    }

    public function testConversationsCanBeRenamedAndRemoved(): void
    {
        $id = $this->chats->create();

        $this->chats->rename($id, '  the quiet question  ');
        self::assertSame('the quiet question', $this->chats->load($id)['title']);

        self::assertTrue($this->chats->remove($id));
        self::assertNull($this->chats->load($id));
    }

    public function testASavedConversationKeepsEveryTurn(): void
    {
        $id = $this->chats->create();

        $messages = [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
            ['role' => 'user', 'content' => 'three'],
        ];

        $this->chats->save($id, $messages, 42);

        $record = $this->chats->load($id);

        self::assertCount(3, $record['messages']);
        self::assertSame(42, $record['tokens']);
    }

    public function testAnIdCannotEscapeTheFolder(): void
    {
        $path = $this->chats->path('../../etc/passwd');

        self::assertSame($this->directory, \dirname($path));
        self::assertStringNotContainsString('/', \basename($path));
    }

    public function testAMissingConversationIsNullNotAThrow(): void
    {
        self::assertNull($this->chats->load('nothing-here'));
    }
}
