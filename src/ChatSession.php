<?php

declare(strict_types=1);

namespace App;

use Boson\Application;
use RuntimeException;

/**
 * The app's own conversation.
 *
 * Deliberately a client of the governed endpoint rather than a special case:
 * if the window can talk to a model without the governor, then the governor is
 * decoration. So this posts to 127.0.0.1:11435 like any other client would, and
 * the pacing, the queue and the temperature ceiling apply to it identically.
 */
final class ChatSession
{
    private readonly Http $http;

    private ?HttpStream $stream = null;

    private string $pending = '';

    private string $model = '';

    private string $state = 'idle';

    private string $error = '';

    private string $answer = '';

    private float $startedAt = 0.0;

    private int $tokens = 0;

    /** @var list<array{role: string, content: string}> */
    private array $history = [];

    /** @var array<string, mixed> */
    private array $lastUsage = [];

    /** Whether the message being answered carried an image. */
    private bool $withImages = false;

    /** One retry is allowed, and only for the case that needs it. */
    private int $attempts = 0;

    /** Tokens spent reasoning in this answer, which the reader never sees. */
    private int $thinking = 0;

    private ?bool $thinks = null;

    /** Vision models are asked non-streaming, for a measured reason. */
    private bool $plainJson = false;

    /** Which conversation this is. A new one is made lazily, on the first ask. */
    private ?string $chatId = null;

    /**
     * The window is optional, the same way it is for the agent: the send path can
     * then be exercised without opening one.
     */
    public function __construct(
        private readonly ?Application $app,
        private readonly Ollama $ollama,
        private readonly Governor $governor,
        private readonly Guard $guard,
        private readonly ?Attachments $attachments = null,
        private readonly ?Vision $vision = null,
        private readonly ?Chats $chats = null,
        private readonly ?Context $context = null,
    ) {
        $this->http = new Http(Guard::HOST, Guard::PORT);
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'state' => $this->state,
            'model' => $this->model,
            'error' => $this->error,
            'tokens' => $this->tokens,
            'seconds' => $this->startedAt === 0.0 ? 0.0 : \round(\microtime(true) - $this->startedAt, 1),
            'answer' => $this->answer,
            'turns' => \count($this->history),
            'attempts' => $this->attempts,
            'chat' => $this->chatId,
            'chat_title' => $this->chatId === null || $this->chats === null
                ? ''
                : (string) (($this->chats->load($this->chatId) ?? [])['title'] ?? ''),
            'plain' => $this->plainJson,
            'usage' => $this->lastUsage,
            'thinking' => $this->thinking,
        ];
    }

    /**
     * The request body.
     *
     * Reasoning models are *allowed* to think — see the agent, where the same
     * measurement applies: with thinking off, qwen3 reasons in the open, in
     * `content`, and the window fills with chain-of-thought. With it on, the
     * reasoning arrives in `thinking` and `content` is the answer. The prediction
     * budget is doubled for those models because thinking counts against it.
     */
    private function payload(): array
    {
        /*
         * A picture changes the request, and this is measured rather than guessed.
         * Against moondream on this engine, the identical request returned an
         * answer or nothing depending on how it was asked (three runs per case):
         *
         *   stream=false, 320 tokens, temperature 0.2   ANSWER ANSWER ANSWER
         *   stream=false, 1,024 tokens, no temperature  empty  empty  empty
         *   stream=true,  320 tokens, temperature 0.2   empty  ANSWER empty
         *   stream=true,  1,024 tokens, no temperature  empty  empty  empty
         *
         * So images are asked in one piece, with a modest budget and a little
         * warmth — which is also what the vision tool does, and why it works.
         */
        if ($this->plainJson) {
            /*
             * A fresh load, and straight to the engine rather than through the
             * guard. Both are measured, not preferred:
             *
             *  - the same request answered 8 times in 10 through one path and 1 in
             *    6 through the other, minutes apart, and a cold load then answered
             *    first time (the vision tool does exactly this and works);
             *  - the guard exists to pace *generations*, and a picture answer is a
             *    short one; vision is not what has ever made this machine loud.
             *
             * Text answers still go through the governor's door, which is where
             * the pacing matters.
             */
            $this->ollama->unload($this->model);

            return [
                'model' => $this->model,
                'messages' => $this->history,
                'stream' => false,
                'options' => [
                    'num_predict' => 512,
                    'temperature' => 0.2,
                    'num_ctx' => (int) $this->governor->current()['num_ctx'],
                    'keep_alive' => $this->governor->keepAlive(),
                ],
            ];
        }

        $thinks = $this->thinks ??= $this->supportsThinking();
        $history = $this->history;

        /*
         * The chat gets the same ladder the agent has.
         *
         * It did not, and that was the last place in the application where a long
         * conversation could fail *silently*: past the window, Ollama drops the oldest
         * turns and the model answers as if the question had never been asked — the
         * exact failure `Context` exists to prevent, and the only path still exposed
         * to it. So the request is trimmed here, and said out loud when it happens.
         *
         * What is trimmed is the *request*, not the conversation: the JSON file keeps
         * every turn the reader wrote, and the window can be scrolled back through it.
         * An application that quietly edited your history to fit its own window would be
         * a worse failure than the one being fixed.
         */
        if ($this->context !== null) {
            $trimmed = $this->context->trim($history, $this->model, (int) $this->governor->current()['num_ctx']);
            $history = $trimmed['messages'];

            if ($trimmed['trimmed'] > 0 || $trimmed['digested'] > 0 || $trimmed['dropped'] > 0) {
                $this->governor->note('context', sprintf(
                    'chat: trimmed %d result(s), digested %d older one(s), dropped %d message(s) to fit the %s-token window — the conversation itself is untouched',
                    $trimmed['trimmed'],
                    $trimmed['digested'],
                    $trimmed['dropped'],
                    \number_format($trimmed['budget']),
                ));
            }
        }

        $payload = [
            'model' => $this->model,
            'messages' => $history,
            'stream' => true,
            'options' => $this->governor->options(
                ['num_ctx' => (int) $this->governor->current()['num_ctx'] * 2],
                $thinks ? 2 : 1,
            ),
        ];

        if ($thinks) {
            $payload['think'] = true;
        }

        return $payload;
    }

    private function supportsThinking(): bool
    {
        $capabilities = $this->ollama->show($this->model)['capabilities'] ?? [];

        return \is_array($capabilities) && \in_array('thinking', $capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function send(string $prompt, string $model, string $attachment = ''): array
    {
        if ($this->state === 'generating') {
            return ['ok' => false, 'error' => 'a generation is already running'];
        }

        if (!$this->ollama->probe()['up']) {
            return ['ok' => false, 'error' => 'ollama is not running on ' . Ollama::HOST . ':' . Ollama::PORT];
        }

        $prompt = \trim($prompt);

        if ($prompt === '') {
            return ['ok' => false, 'error' => 'nothing to ask'];
        }

        if ($model === '') {
            return ['ok' => false, 'error' => 'pick a model first'];
        }

        $images = [];
        $note = '';

        if ($attachment !== '' && $this->attachments !== null) {
            $loaded = $this->attachments->load($attachment);

            if (($loaded['ok'] ?? false) !== true) {
                return ['ok' => false, 'error' => (string) ($loaded['error'] ?? 'that file cannot be attached')];
            }

            $note = (string) ($loaded['note'] ?? '');

            if (($loaded['kind'] ?? '') === 'image') {
                /*
                 * A picture goes to the vision tool's own path — the same code the
                 * agent calls — rather than a second implementation here.
                 *
                 * That is not tidiness. Two paths asking the same question of the
                 * same model behaved differently: the tool answered first time,
                 * every time, while the chat's own request came back empty over
                 * and over, and four payload shapes measured 2–3 successes in 5
                 * either way. One implementation, used by both, is the fix that
                 * the evidence actually supports.
                 */
                $this->model = $model;
                $this->state = 'generating';
                $this->push('onStarted', ['model' => $model, 'prompt' => $prompt, 'attachment' => $note]);

                // Vision speaks in workspace-relative paths; the file picker gave
                // us an absolute one.
                $relative = $this->attachments->relative($attachment);

                $seen = $relative === null
                    ? ['ok' => false, 'output' => 'that image is not inside the working folder']
                    : ($this->vision?->describe($relative, $prompt)
                        ?? ['ok' => false, 'output' => 'no vision model is available']);

                if (($seen['ok'] ?? false) === true) {
                    $this->answer = (string) $seen['output'];
                    $this->tokens = \max(1, \str_word_count($this->answer));
                    $this->state = 'done';
                    $this->history[] = ['role' => 'user', 'content' => $prompt . ' [image: ' . ($loaded['name'] ?? '') . ']'];
                    $this->history[] = ['role' => 'assistant', 'content' => $this->answer];
                    $this->push('onToken', ['text' => $this->answer, 'tokens' => $this->tokens]);
                    $this->push('onFinished', [
                        'state' => 'done',
                        'error' => '',
                        'tokens' => $this->tokens,
                        'usage' => [],
                        'thinking' => 0,
                    ]);

                    return ['ok' => true, 'model' => $model];
                }

                $this->error = (string) ($seen['output'] ?? 'the vision model said nothing');
                $this->state = 'failed';
                $this->push('onFailed', ['error' => $this->error]);

                return ['ok' => false, 'error' => $this->error];
            }

            $prompt .= "\n\n--- " . ($loaded['name'] ?? 'attachment') . " ---\n" . ($loaded['text'] ?? '');
        }

        $message = ['role' => 'user', 'content' => $prompt];

        if ($images !== []) {
            $message['images'] = $images;
        }

        $this->history[] = $message;
        $this->withImages = $images !== [];
        $this->plainJson = $images !== [];
        $this->attempts = 1;
        $this->model = $model;
        $this->answer = '';
        $this->error = '';
        $this->tokens = 0;
        $this->pending = '';
        $this->lastUsage = [];
        $this->startedAt = \microtime(true);

        $payload = $this->payload();

        try {
            $this->stream = $this->http->open('POST', '/api/chat', $payload, 30.0);
        } catch (RuntimeException $e) {
            $this->state = 'failed';
            $this->error = $e->getMessage();

            return ['ok' => false, 'error' => $this->error];
        }

        $this->state = 'generating';
        $this->push('onStarted', ['model' => $model, 'prompt' => $prompt, 'attachment' => $note]);

        return ['ok' => true, 'model' => $model];
    }

    /** The conversation in use, made if there is not one yet. */
    public function chatId(): ?string
    {
        if ($this->chatId === null && $this->chats !== null) {
            $this->chatId = $this->chats->create();
        }

        return $this->chatId;
    }

    /** Start a new conversation: the old one stays on disk, untouched. */
    public function newChat(): array
    {
        if ($this->state === 'generating') {
            return ['ok' => false, 'error' => 'an answer is being written'];
        }

        $this->history = [];
        $this->answer = '';
        $this->error = '';
        $this->tokens = 0;
        $this->state = 'idle';
        $this->chatId = $this->chats?->create() ?? null;
        $this->push('onCleared', []);

        return ['ok' => true, 'chat' => $this->chatId];
    }

    /**
     * Ask the last question again, without its answer.
     *
     * Pure history surgery, kept separate from the asking so it can be tested:
     * the previous answer is dropped, the question stays.
     *
     * @param list<array<string, mixed>> $history
     *
     * @return list<array<string, mixed>>
     */
    public static function historyForRegenerate(array $history): array
    {
        while ($history !== [] && (\end($history)['role'] ?? '') === 'assistant') {
            \array_pop($history);
        }

        return \array_values($history);
    }

    /**
     * Replace the last question, and drop everything that followed it.
     *
     * Editing a question is not editing text: the answers after it were answers to
     * something else, so they go rather than sitting underneath a question they do
     * not belong to.
     *
     * @param list<array<string, mixed>> $history
     *
     * @return list<array<string, mixed>>
     */
    public static function historyForEdit(array $history, string $question): array
    {
        for ($index = \count($history) - 1; $index >= 0; $index--) {
            if (($history[$index]['role'] ?? '') === 'user') {
                $history[$index]['content'] = $question;

                return \array_values(\array_slice($history, 0, $index + 1));
            }
        }

        return \array_values($history);
    }

    /** @return array<string, mixed> */
    public function regenerate(): array
    {
        if ($this->state === 'generating') {
            return ['ok' => false, 'error' => 'an answer is already being written'];
        }

        if ($this->history === []) {
            return ['ok' => false, 'error' => 'there is nothing to ask again'];
        }

        $history = self::historyForRegenerate($this->history);

        if ($history === [] || ($history[\count($history) - 1]['role'] ?? '') !== 'user') {
            return ['ok' => false, 'error' => 'the last message was not a question'];
        }

        $question = (string) $history[\count($history) - 1]['content'];
        $this->history = $history;
        $this->answer = '';
        $this->error = '';
        $this->tokens = 0;
        $this->attempts = 1;
        $this->withImages = false;
        $this->plainJson = false;
        $this->startedAt = \microtime(true);

        try {
            $this->stream = $this->http->open('POST', '/api/chat', $this->payload(), 30.0);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->state = 'failed';

            return ['ok' => false, 'error' => $this->error];
        }

        $this->state = 'generating';
        $this->push('onStarted', ['model' => $this->model, 'prompt' => $question, 'attachment' => '', 'again' => true]);

        return ['ok' => true, 'question' => $question];
    }

    /** Change the last question and ask it in its place. */
    public function editQuestion(string $question): array
    {
        $question = \trim($question);

        if ($question === '') {
            return ['ok' => false, 'error' => 'the question cannot be empty'];
        }

        if ($this->state === 'generating') {
            return ['ok' => false, 'error' => 'an answer is already being written'];
        }

        $this->history = self::historyForEdit($this->history, $question);
        $this->answer = '';
        $this->error = '';
        $this->tokens = 0;
        $this->attempts = 1;
        $this->withImages = false;
        $this->plainJson = false;
        $this->startedAt = \microtime(true);

        try {
            $this->stream = $this->http->open('POST', '/api/chat', $this->payload(), 30.0);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->state = 'failed';

            return ['ok' => false, 'error' => $this->error];
        }

        $this->state = 'generating';
        $this->push('onStarted', ['model' => $this->model, 'prompt' => $question, 'attachment' => '', 'edited' => true]);

        return ['ok' => true, 'question' => $question];
    }

    /** Continue an earlier conversation. */
    public function useChat(string $id): array
    {
        if ($this->state === 'generating') {
            return ['ok' => false, 'error' => 'an answer is being written'];
        }

        $record = $this->chats?->load($id);

        if ($record === null) {
            return ['ok' => false, 'error' => 'no conversation called that'];
        }

        $messages = \is_array($record['messages'] ?? null) ? $record['messages'] : [];

        $this->history = \array_values(\array_filter($messages, static function (mixed $message): bool {
            // Image data does not belong in the reloaded history: it is megabytes
            // per picture and the model would be charged for it on every turn.
            return \is_array($message) && !isset($message['images']);
        }));

        $this->chatId = $id;
        $this->answer = '';
        $this->error = '';
        $this->tokens = (int) ($record['tokens'] ?? 0);
        $this->state = 'idle';

        $this->push('onHistory', ['messages' => $this->history, 'chat' => $id]);

        return ['ok' => true, 'chat' => $id, 'turns' => \count($this->history)];
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        if ($this->stream === null) {
            return ['ok' => false, 'error' => 'nothing is running'];
        }

        $this->stream->abort();
        $this->stream = null;
        $this->state = 'stopped';
        $this->record('');
        $this->push('onStopped', ['tokens' => $this->tokens]);

        return ['ok' => true];
    }

    public function forget(): void
    {
        $this->stop();
        $this->history = [];
        $this->answer = '';
        $this->state = 'idle';
        $this->push('onCleared', []);
    }

    /** Step the stream: called from the application loop. */
    public function tick(): void
    {
        if ($this->stream === null) {
            return;
        }

        if ($this->stream->finished()) {
            $this->settle('done');

            return;
        }

        $bytes = $this->stream->pump();

        if ($bytes === '') {
            return;
        }

        $this->pending .= $bytes;

        // Non-streaming: the whole body arrives at once, and the answer is in it.
        if ($this->plainJson) {
            if (!$this->stream->finished()) {
                return;
            }

            $body = \json_decode(\trim($this->pending), true);
            $content = \is_array($body) ? (string) (($body['message']['content'] ?? '')) : '';

            if ($content !== '') {
                $this->answer .= $content;
                $this->tokens += \max(1, \str_word_count($content));
                $this->push('onToken', ['text' => $content, 'tokens' => $this->tokens]);
            }

            $this->settle('done');

            return;
        }

        while (($newline = \strpos($this->pending, "\n")) !== false) {
            $line = \trim(\substr($this->pending, 0, $newline));
            $this->pending = \substr($this->pending, $newline + 1);

            if ($line === '') {
                continue;
            }

            $event = \json_decode($line, true);

            if (!\is_array($event)) {
                continue;
            }

            if (isset($event['error'])) {
                $this->state = 'failed';
                $this->error = (string) $event['error'];
                $this->push('onFailed', ['error' => $this->error]);
                $this->settle('failed');

                return;
            }

            $message = \is_array($event['message'] ?? null) ? $event['message'] : [];
            // A reasoning model streams its thinking first, in a field of its own.
            // Counting it is not decoration: it is how "it answered nothing" is
            // told apart from "it is still thinking", which looked identical here.
            if (($message['thinking'] ?? '') !== '') {
                $this->thinking++;
            }

            $piece = isset($message['content']) ? (string) $message['content'] : '';

            if ($piece !== '') {
                $this->answer .= $piece;
                $this->tokens++;
                $this->push('onToken', ['text' => $piece, 'tokens' => $this->tokens]);
            }

            if ((bool) ($event['done'] ?? false)) {
                $this->lastUsage = [
                    'prompt' => (int) ($event['prompt_eval_count'] ?? 0),
                    'completion' => (int) ($event['eval_count'] ?? 0),
                    'seconds' => isset($event['total_duration']) ? \round((int) $event['total_duration'] / 1_000_000_000, 2) : null,
                ];
            }
        }

        if ($this->stream->finished()) {
            $this->settle('done');
        }
    }

    private function settle(string $state): void
    {
        // A vision model answers nothing now and then — measured, repeatedly. The
        // agent's vision tool already asks again; the chat has to as well, or the
        // window shows an empty bubble and looks broken.
        // Three attempts, not two, and the reason is measured rather than felt:
        // moondream on this engine answers a picture and then answers *nothing*
        // for the identical request, run to run — seven option combinations,
        // three runs each, showed no setting that fixes it. So the app asks
        // again, and if the model still says nothing, it says so.
        $limit = $this->withImages ? 3 : 2;

        if ($state === 'done' && \trim($this->answer) === '' && ($this->withImages || $this->thinking > 0) && $this->attempts < $limit) {
            // Measured, three times over: moondream answers *nothing at all* to a
            // pointed question about a picture ("what word is on the sign?"), and
            // answers readily to "describe this image plainly". So the retry is
            // not a repeat — it is the same question asked the way that model can
            // hear it, and the window is told that is what happened.
            // Rewrite the message being answered rather than adding another: a
            // vision model looks at the images *on the message it is answering*,
            // so a follow-up with no images attached has nothing to look at — and
            // answers nothing, which is exactly what the first version of this did.
            for ($index = \count($this->history) - 1; $index >= 0; $index--) {
                if (($this->history[$index]['role'] ?? '') === 'user') {
                    $this->history[$index]['content'] = $this->withImages
                        ? 'Describe this image plainly: what it shows, and any text in it.'
                        : 'Answer with one short sentence. Do not reason at length.';

                    break;
                }
            }

            $this->attempts++;
            $this->error = '';
            $this->tokens = 0;
            $this->thinking = 0;
            $this->pending = '';
            $this->answer = '';

            $payload = $this->payload();

            try {
                $this->stream = $this->http->open('POST', '/api/chat', $payload, 30.0);
                $this->state = 'generating';
                $this->push('onStarted', [
                    'model' => $this->model,
                    'prompt' => $this->withImages
                        ? '(it answered nothing to the question, so it is being asked plainly)'
                        : '(it spent its budget thinking, so it is being asked to answer briefly)',
                    'attachment' => '',
                ]);

                return;
            } catch (\RuntimeException $e) {
                $this->error = $e->getMessage();
            }
        }

        if ($state === 'done' && \trim($this->answer) === '') {
            // Never leave an empty bubble with no explanation, and leave the model
            // in a clean state for the next try: with a small vision model, the
            // same request answers eight times in ten and then one time in six,
            // and a cold load is the difference.
            $state = 'failed';
            $this->error = 'the model returned nothing, three times over (it does that, unpredictably). '
                . 'It has been unloaded so the next attempt starts fresh.';

            if ($this->withImages) {
                $this->ollama->unload($this->model);
            }
        }

        $this->state = $state;
        $this->stream = null;
        $this->record($this->answer);

        if ($this->chats !== null && $this->chatId !== null) {
            $this->chats->save($this->chatId, $this->history, $this->tokens);
        }
        $this->push('onFinished', [
            'state' => $state,
            'error' => $this->error,
            'tokens' => $this->tokens,
            'usage' => $this->lastUsage,
        ]);
    }

    private function record(string $answer): void
    {
        if ($answer === '') {
            return;
        }

        $last = \end($this->history);

        if (\is_array($last) && ($last['role'] ?? '') === 'assistant') {
            return;
        }

        $this->history[] = ['role' => 'assistant', 'content' => $answer];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function push(string $event, array $payload): void
    {
        if ($this->app === null) {
            return;
        }

        $script = \sprintf(
            'window.quiesce && window.quiesce.%s(%s);',
            $event,
            \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        try {
            $this->app->webview->scripts->eval($script);
        } catch (\Throwable) {
            // The window may not be up yet, or may be closing. A dropped UI
            // update is not worth an exception in the middle of a generation.
        }
    }
}
