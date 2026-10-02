<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Chats;
use App\ChatSession;
use PHPUnit\Framework\TestCase;

/**
 * Editing and re-asking: the history surgery, kept pure so it can be tested
 * without a model. What is at stake is only ever one question — does the old
 * answer go, and does the question that remains match what is on screen.
 */
final class ChatHistoryTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function history(): array
    {
        return [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'first answer'],
            ['role' => 'user', 'content' => 'second question'],
            ['role' => 'assistant', 'content' => 'second answer'],
        ];
    }

    public function testAskingAgainDropsTheAnswerAndKeepsTheQuestion(): void
    {
        $history = ChatSession::historyForRegenerate($this->history());

        self::assertCount(4, $history);
        self::assertSame('second question', $history[3]['content']);
        self::assertSame('user', $history[3]['role']);
    }

    public function testAskingAgainTwiceInARowIsHarmless(): void
    {
        $history = $this->history();
        $history[] = ['role' => 'assistant', 'content' => 'another'];
        $history[] = ['role' => 'assistant', 'content' => 'and another'];

        $trimmed = ChatSession::historyForRegenerate($history);

        self::assertSame('second question', $trimmed[count($trimmed) - 1]['content']);
        self::assertCount(4, $trimmed);
    }

    public function testEditingAQuestionDropsEverythingAfterIt(): void
    {
        $history = ChatSession::historyForEdit($this->history(), 'second question, reworded');

        self::assertCount(4, $history);
        self::assertSame('second question, reworded', $history[3]['content']);
        self::assertSame('you are an agent', $history[0]['content'], 'the system prompt survives');
        self::assertSame('first answer', $history[2]['content'], 'earlier turns are untouched');
    }

    public function testEditingWithNoQuestionYetChangesNothing(): void
    {
        $history = [['role' => 'system', 'content' => 'you are an agent']];

        self::assertSame($history, ChatSession::historyForEdit($history, 'anything'));
    }

    public function testSearchFindsAConversationByItsTitle(): void
    {
        $directory = sys_get_temp_dir() . '/quiesce-search-' . bin2hex(random_bytes(4));
        $chats = new Chats($directory);
        $id = $chats->create();
        $chats->save($id, [['role' => 'user', 'content' => 'How hot may the card get?']]);

        $matches = $chats->search('card');

        self::assertCount(1, $matches);
        self::assertSame($id, $matches[0]['id']);
        self::assertSame('title', $matches[0]['where']);

        foreach (glob($directory . '/*.json') ?: [] as $file) { @unlink($file); }
        @rmdir($directory);
    }

    public function testSearchFindsAConversationByWhatWasSaidInIt(): void
    {
        $directory = sys_get_temp_dir() . '/quiesce-search-' . bin2hex(random_bytes(4));
        $chats = new Chats($directory);
        $id = $chats->create();
        $chats->save($id, [
            ['role' => 'user', 'content' => 'summarise this'],
            ['role' => 'assistant', 'content' => 'The whisper profile holds the card at 66 degrees.'],
        ]);
        $chats->rename($id, 'a summary');

        $matches = $chats->search('whisper');

        self::assertCount(1, $matches);
        self::assertSame('the answer', $matches[0]['where']);
        self::assertStringContainsString('whisper', strtolower($matches[0]['snippet']));

        foreach (glob($directory . '/*.json') ?: [] as $file) { @unlink($file); }
        @rmdir($directory);
    }

    public function testASingleLetterIsNotASearch(): void
    {
        $chats = new Chats(sys_get_temp_dir() . '/quiesce-search-' . bin2hex(random_bytes(4)));

        self::assertSame([], $chats->search('a'));
        self::assertSame([], $chats->search('  '));
    }
}
