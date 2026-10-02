<?php

declare(strict_types=1);

namespace App;

/**
 * Keeping a conversation inside the model's window.
 *
 * A real file blows through an 8k context in two reads, and the failure mode of
 * running out is ugly: Ollama silently drops the oldest turns and the model
 * answers as if the task had never been set. So this trims *deliberately*, in
 * order, and says what it did:
 *
 *   1. old tool output is shortened, newest results kept whole;
 *   2. then old tool output is replaced by its *digest* — the call with its
 *      arguments, the size of what came back, and the first line of it — which
 *      keeps the shape of the work even when the contents are gone;
 *   3. only then are the oldest exchanges dropped.
 *
 * Deterministic on purpose. A model-written summary would be nicer, but it costs
 * a generation, can fail, and is one more thing that answers with the wrong thing
 * in it. The counts of what was shortened, digested and dropped are reported
 * instead, and shown in the window.
 *
 * Rung 2 is the mechanical half of what other agents call *auto-compaction*, and
 * the reason it is this rung rather than a model's is worth stating: at an 8k
 * window the thing that kills a long task is not losing the words, it is losing
 * the *shape* — a model four steps later cannot tell whether it has read a file or
 * merely asked for it, and re-reads everything or invents the difference. A call
 * with its arguments, its size and its first line is counted from the transcript
 * rather than composed, so it can be wrong about nothing; a model-written summary
 * is a paragraph that can be confidently wrong, and in this application a
 * confident wrong answer is the one failure that is not allowed.
 */
final class Context
{
    /** Roughly what every local runner uses: a token is about four characters. */
    public const int CHARS_PER_TOKEN = 4;

    /** Tool output kept whole for the most recent calls. */
    public const int KEEP_RECENT = 2;

    public const int RECENT_BYTES = 2_400;

    public const int OLD_BYTES = 400;

    /** The share of the window a conversation may fill before trimming starts. */
    public const float FILL = 0.7;

    public function __construct(private readonly ?Ollama $ollama = null) {}

    /** @param list<array<string, mixed>> $messages */
    public static function estimate(array $messages): int
    {
        $characters = 0;

        foreach ($messages as $message) {
            $characters += \strlen((string) ($message['content'] ?? ''));

            if (\is_array($message['tool_calls'] ?? null)) {
                $characters += \strlen((string) \json_encode($message['tool_calls']));
            }
        }

        return (int) \ceil($characters / self::CHARS_PER_TOKEN);
    }

    /** What the model itself was trained to hold, asked of the engine. */
    public function window(string $model): ?int
    {
        if ($this->ollama === null || $model === '') {
            return null;
        }

        $info = $this->ollama->show($model)['model_info'] ?? [];
        $longest = null;

        foreach (\is_array($info) ? $info : [] as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.context_length') && \is_numeric($value)) {
                $longest = \max((int) $longest, (int) $value);
            }
        }

        return $longest;
    }

    /**
     * The number of tokens a conversation may use: the smaller of what the model
     * holds and what the profile allows it to be loaded with.
     */
    public function budget(string $model, int $profileContext): int
    {
        $window = $this->window($model) ?? $profileContext;
        $effective = \min($window, $profileContext);

        return (int) \floor($effective * self::FILL);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array{messages: list<array<string, mixed>>, trimmed: int, digested: int, dropped: int, tokens: int, budget: int}
     */
    public function trim(array $messages, string $model, int $profileContext): array
    {
        $budget = $this->budget($model, $profileContext);

        if (self::estimate($messages) <= $budget) {
            return ['messages' => $messages, 'trimmed' => 0, 'digested' => 0, 'dropped' => 0, 'tokens' => self::estimate($messages), 'budget' => $budget];
        }

        $trimmed = 0;
        $digested = 0;
        $toolIndexes = [];
        $whole = [];

        foreach ($messages as $index => $message) {
            if (($message['role'] ?? '') === 'tool') {
                $toolIndexes[] = $index;
                // What arrived, before any rung touches it: the digest's "six lines,
                // 2.4 KB" has to describe the result, not the part of it left over.
                $whole[$index] = (string) ($message['content'] ?? '');
            }
        }

        $calls = self::callsByToolMessageId($messages);

        // 1 and 2: shorten old tool output, then replace it with its own digest.
        $old = \array_slice($toolIndexes, 0, \max(0, \count($toolIndexes) - self::KEEP_RECENT));

        foreach ($old as $index) {
            $content = (string) ($messages[$index]['content'] ?? '');

            if (\strlen($content) > self::RECENT_BYTES) {
                $messages[$index]['content'] = \substr($content, 0, self::RECENT_BYTES) . "\n… (shortened to fit the model's window)";
                $trimmed++;
            }
        }

        if (self::estimate($messages) > $budget) {
            foreach ($old as $index) {
                if (\strlen($whole[$index] ?? '') <= self::OLD_BYTES) {
                    continue;
                }

                $messages[$index]['content'] = self::digest(
                    $whole[$index],
                    $calls[(string) ($messages[$index]['tool_call_id'] ?? '')] ?? 'a tool call (arguments not in this history)',
                );
                $digested++;
            }
        }

        // 3: drop the oldest exchanges, keeping the system prompt and the task.
        $dropped = 0;
        $floor = self::floorIndex($messages);

        while (self::estimate($messages) > $budget && \count($messages) > $floor + 3) {
            $victim = null;

            for ($index = $floor; $index < \count($messages) - 1; $index++) {
                if (($messages[$index]['role'] ?? '') !== 'system') {
                    $victim = $index;

                    break;
                }
            }

            if ($victim === null) {
                break;
            }

            \array_splice($messages, $victim, 1);
            $dropped++;
        }

        $messages = \array_values($messages);

        return [
            'messages' => $messages,
            'trimmed' => $trimmed,
            'digested' => $digested,
            'dropped' => $dropped,
            'tokens' => self::estimate($messages),
            'budget' => $budget,
        ];
    }

    /**
     * The messages that must survive: the system prompt at the top, and the
     * original task, which is the first user message.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function floorIndex(array $messages): int
    {
        $seenSystem = false;
        $seenTask = false;

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? '';

            if ($role === 'system') {
                $seenSystem = true;

                continue;
            }

            if ($seenSystem && !$seenTask && $role === 'user') {
                $seenTask = true;
            } elseif ($seenTask) {
                return $index;
            }
        }

        return \max(0, \count($messages) - 1);
    }

    /**
     * One line where a long result used to be: what was asked for, how much came
     * back, and the first thing it said.
     *
     * Every part of this is counted from the transcript — the call and its arguments
     * from the assistant message that asked for it, the size and the first line from
     * the result itself. Nothing here is composed, so nothing here can be wrong; the
     * worst it can do is be terse. That is the whole argument for a digest over a
     * summary in an application whose promise is that it refuses rather than
     * guesses.
     */
    private static function digest(string $content, string $call): string
    {
        $lines = $content === '' ? 0 : \substr_count($content, "\n") + 1;
        $head = '';

        foreach (\preg_split('/\R/', \trim($content)) ?: [] as $line) {
            $line = \trim($line);

            if ($line !== '') {
                $head = \substr($line, 0, 120);

                break;
            }
        }

        $digest = '(digest: ' . $call . ' → ' . $lines . ' line(s), ' . self::size(\strlen($content));

        return $head === '' ? $digest . ')' : $digest . ' · ' . $head . ')';
    }

    /**
     * `tool_call_id` → `read_file(path=notes.md)`, so a digest can say what was
     * asked for. The bulky arguments are left out on purpose: an agent that needs
     * the content of a write has the file, and the point of the line is to say
     * which call it was, not to re-state it.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, string>
     */
    private static function callsByToolMessageId(array $messages): array
    {
        $labels = [];

        foreach ($messages as $message) {
            if (!\is_array($message['tool_calls'] ?? null)) {
                continue;
            }

            foreach ($message['tool_calls'] as $call) {
                $id = (string) ($call['id'] ?? '');

                if ($id !== '' && \is_array($call)) {
                    $labels[$id] = self::callLabel($call);
                }
            }
        }

        return $labels;
    }

    /** @param array<string, mixed> $call */
    private static function callLabel(array $call): string
    {
        $name = (string) ($call['function']['name'] ?? $call['name'] ?? 'a tool');
        $arguments = $call['function']['arguments'] ?? [];

        if (\is_string($arguments)) {
            $decoded = \json_decode($arguments, true);
            $arguments = \is_array($decoded) ? $decoded : [];
        }

        $bits = [];
        $preferred = ['path', 'command', 'query', 'url', 'pattern'];
        $skip = ['content', 'find', 'replace', 'all'];

        foreach ($preferred as $key) {
            if (isset($arguments[$key]) && \is_scalar($arguments[$key])) {
                $bits[] = $key . '=' . \substr((string) $arguments[$key], 0, 60);
            }
        }

        if ($bits === []) {
            foreach ($arguments as $key => $value) {
                if (\is_scalar($value) && !\in_array((string) $key, $skip, true)) {
                    $bits[] = (string) $key . '=' . \substr((string) $value, 0, 60);

                    break;
                }
            }
        }

        return $name . ($bits === [] ? '()' : '(' . \implode(', ', \array_slice($bits, 0, 2)) . ')');
    }

    /** A size a reader can judge at a glance, because the number that matters here
     *  is "was that a whole file or a line?" */
    private static function size(int $bytes): string
    {
        return $bytes >= 1_024 * 1_024
            ? \round($bytes / (1_024 * 1_024), 1) . ' MB'
            : ($bytes >= 1_024 ? \round($bytes / 1_024, 1) . ' KB' : $bytes . ' B');
    }
}
