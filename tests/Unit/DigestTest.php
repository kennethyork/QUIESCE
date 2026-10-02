<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Context;
use PHPUnit\Framework\TestCase;

/**
 * The digest rung: what an old tool result becomes when the window runs out.
 *
 * The claim being tested is not "it is shorter" — it is that the line left behind is
 * *counted from the transcript*, so it can say what was asked for and how much came
 * back without being able to invent either. The rung it replaced kept the name of the
 * work; this one keeps what the work was, and the difference is what a model four
 * steps later can act on.
 */
final class DigestTest extends TestCase
{
    public function testAnOldResultBecomesOneLineNamingTheCallAndItsSize(): void
    {
        $trimmed = $this->trimmed(4);

        self::assertSame(2, $trimmed['digested'], 'the two oldest results, and not the newest two');
        self::assertSame(0, $trimmed['dropped'], 'the ladder stops as soon as it fits');

        $digest = (string) $trimmed['messages'][3]['content'];

        self::assertStringContainsString('read_file(path=notes.md)', $digest, 'what was asked for');
        self::assertStringContainsString('line(s)', $digest, 'how much came back');
        self::assertStringContainsString('KB', $digest, 'and how big it was, in a unit a reader can judge');
    }

    public function testTheDigestKeepsTheFirstLineOfWhatCameBackVerbatim(): void
    {
        $trimmed = $this->trimmed(4);
        $digest = (string) $trimmed['messages'][3]['content'];

        // Not summarised, not paraphrased: the first line of the result, as it arrived.
        self::assertStringContainsString('first line of the result', $digest);
    }

    public function testTheNewestResultsAreKeptWhole(): void
    {
        $trimmed = $this->trimmed(4);

        // The two oldest are digested (indexes 3 and 5); the two newest are untouched.
        self::assertStringContainsString('(digest:', (string) $trimmed['messages'][5]['content']);

        foreach ([7, 9] as $newer) {
            self::assertStringNotContainsString('(digest:', (string) $trimmed['messages'][$newer]['content']);
            self::assertStringContainsString('x', (string) $trimmed['messages'][$newer]['content']);
        }
    }

    public function testTheTaskAndTheSystemPromptAlwaysSurvive(): void
    {
        $trimmed = $this->trimmed(4);

        self::assertSame('system', $trimmed['messages'][0]['role']);
        self::assertStringContainsString('you are an agent', (string) $trimmed['messages'][0]['content']);
        self::assertStringContainsString('tidy the notes', (string) $trimmed['messages'][1]['content']);
    }

    public function testAResultWithNoCallInTheHistorySaysSoRatherThanGuessing(): void
    {
        $big = \str_repeat('x', 700);
        $messages = [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'tidy the notes'],
            ['role' => 'tool', 'content' => 'orphaned result ' . $big],
            ['role' => 'tool', 'content' => 'orphaned result ' . $big],
            ['role' => 'tool', 'content' => 'orphaned result ' . $big],
        ];

        $trimmed = (new Context())->trim($messages, 'model', 700);

        self::assertSame(1, $trimmed['digested']);
        self::assertStringContainsString('arguments not in this history', (string) $trimmed['messages'][2]['content']);
    }

    /**
     * A history with `$calls` tool results, each big enough to digest but small enough
     * not to be shortened first — so the rung under test is the one that runs.
     *
     * @return array{messages: list<array<string, mixed>>, trimmed: int, digested: int, dropped: int, tokens: int, budget: int}
     */
    private function trimmed(int $calls): array
    {
        // Over a kilobyte each, so the digest has a size worth printing and the rung
        // that shortens whole results (2.4 KB) is not the one that fires.
        $body = \str_repeat('x', 1_200);
        $messages = [
            ['role' => 'system', 'content' => 'you are an agent'],
            ['role' => 'user', 'content' => 'tidy the notes'],
        ];

        for ($i = 1; $i <= $calls; $i++) {
            $id = 'c' . $i;

            $messages[] = [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => $id,
                    'function' => ['name' => $i % 2 === 1 ? 'read_file' : 'write_file', 'arguments' => '{"path":"notes.md"}'],
                ]],
            ];

            $messages[] = [
                'role' => 'tool',
                'content' => 'first line of the result ' . $i . "\n" . $body,
                'tool_call_id' => $id,
                'tool_name' => 'read_file',
            ];
        }

        // 1,200 tokens of profile window is 840 usable: less than the history as it
        // stands, more than it needs after the digest rung — which is the boundary this
        // file exists to sit on.
        return (new Context())->trim($messages, 'model', 1_200);
    }
}
