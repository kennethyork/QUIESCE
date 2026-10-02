<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Context;
use PHPUnit\Framework\TestCase;

/**
 * Context trimming, tested as decision-making rather than as string surgery:
 * what survives, what is shortened, what is dropped, and whether the report the
 * reader sees is true.
 */
final class ContextTest extends TestCase
{
    public function testItEstimatesATokenPerFourCharacters(): void
    {
        $messages = [
            ['role' => 'system', 'content' => \str_repeat('a', 400)],
            ['role' => 'user', 'content' => \str_repeat('b', 400)],
        ];

        self::assertSame(200, Context::estimate($messages));
    }

    public function testAShortConversationIsLeftAlone(): void
    {
        $context = new Context();
        $messages = [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'read notes.md'],
            ['role' => 'tool', 'content' => 'short result'],
        ];

        $trimmed = $context->trim($messages, 'model', 8_192);

        self::assertSame($messages, $trimmed['messages'], 'nothing to do, so nothing done');
        self::assertSame(0, $trimmed['trimmed']);
        self::assertSame(0, $trimmed['dropped']);
    }

    public function testOldResultsAreShortenedAndTheNewestKeptWhole(): void
    {
        $context = new Context();

        $messages = [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'the task'],
        ];

        // Five large results: the two newest must survive untouched.
        for ($i = 0; $i < 5; $i++) {
            $messages[] = ['role' => 'assistant', 'content' => 'calling tool ' . $i];
            $messages[] = ['role' => 'tool', 'content' => \str_repeat('x', 8_000)];
        }

        $trimmed = $context->trim($messages, 'model', 8_192);

        self::assertGreaterThan(0, $trimmed['trimmed'], 'something had to give');
        self::assertLessThanOrEqual($trimmed['budget'], $trimmed['tokens'], 'and the result fits the budget');

        $tools = \array_values(\array_filter(
            $trimmed['messages'],
            static fn (array $message): bool => ($message['role'] ?? '') === 'tool',
        ));

        self::assertGreaterThan(0, \count($tools));
        self::assertGreaterThan(1_000, \strlen((string) \end($tools)['content']), 'the newest result is still whole');
    }

    public function testTheSystemPromptAndTheTaskAlwaysSurvive(): void
    {
        $context = new Context();

        $messages = [
            ['role' => 'system', 'content' => 'YOU ARE AN AGENT'],
            ['role' => 'user', 'content' => 'THE ORIGINAL TASK'],
        ];

        for ($i = 0; $i < 40; $i++) {
            $messages[] = ['role' => 'assistant', 'content' => \str_repeat('a', 500)];
            $messages[] = ['role' => 'tool', 'content' => \str_repeat('t', 500)];
        }

        $trimmed = $context->trim($messages, 'model', 4_096);

        self::assertGreaterThan(0, $trimmed['dropped'], 'this conversation had to lose something');
        self::assertSame('YOU ARE AN AGENT', $trimmed['messages'][0]['content']);
        self::assertSame('THE ORIGINAL TASK', $trimmed['messages'][1]['content']);
        self::assertLessThanOrEqual($trimmed['budget'], $trimmed['tokens']);
    }

    public function testTheBudgetIsTheSmallerOfTheModelAndTheProfile(): void
    {
        $context = new Context();

        // No engine to ask, so the profile's context stands for the window.
        self::assertSame(2_867, $context->budget('model', 4_096));

        // A model with a smaller window than the profile's context wins.
        self::assertSame(2_867, $context->budget('model', 4_096));
    }

    public function testTheFloorIsWhereTheTaskEnds(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'the task'],
            ['role' => 'assistant', 'content' => 'working'],
            ['role' => 'tool', 'content' => 'result'],
        ];

        self::assertSame(2, Context::floorIndex($messages));
    }

    public function testAnEmptyHistoryIsHarmless(): void
    {
        $context = new Context();

        $trimmed = $context->trim([], 'model', 4_096);

        self::assertSame([], $trimmed['messages']);
        self::assertSame(0, $trimmed['tokens']);
    }
}
