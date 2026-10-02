<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * The governed endpoint.
 *
 * This is the part that turns "the app is quiet" into "the machine is quiet".
 * A coding agent looping tool calls does not go through a chat window — it goes
 * straight at Ollama over HTTP. So the guarantee has to sit in the socket: this
 * class listens on loopback (127.0.0.1:11435, never anything else), speaks the
 * shapes Ollama and OpenAI clients already use, and forwards to Ollama one
 * generation at a time, at the pace the governor allows.
 *
 * Everything here is stepped from the application loop and never blocks: a
 * generation in flight is throttled by declining to read from Ollama, which
 * fills the socket buffer, which blocks the model's next write. Nothing is
 * killed mid-sentence; it is stalled, then resumed.
 *
 * Routes:
 *   GET  /api/version /api/tags /api/ps   — passed through, unthrottled
 *   POST /api/show                        — passed through
 *   POST /api/chat /api/generate          — governed, Ollama-native passthrough
 *   GET  /v1/models                       — built from /api/tags
 *   POST /v1/chat/completions             — governed, translated to /api/chat
 */
final class Guard
{
    public const string HOST = '127.0.0.1';

    public const int PORT = 11435;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array<string, mixed>> */
    private array $clients = [];

    /** @var list<int> */
    private array $queue = [];

    private ?int $inflight = null;

    private ?HttpStream $upstream = null;

    /** Seconds the current generation has actually been allowed to run. */
    private float $generated = 0.0;

    private float $lastTick = 0.0;

    private float $secondAt = 0.0;

    private int $tokensThisSecond = 0;

    private float $cooldownUntil = 0.0;

    private int $nextId = 1;

    private float $listeningSince = 0.0;

    /** @var array<string, int> */
    private array $counters = [
        'connections' => 0,
        'generations' => 0,
        'queued' => 0,
        'held' => 0,
        'paced' => 0,
        'refused' => 0,
        'failed' => 0,
    ];

    /** @var list<array{t: float, message: string}> */
    private array $log = [];

    private string $reason = 'idle';

    /** @var array<string, bool> model => does it reason before answering */
    private array $thinks = [];

    public function __construct(
        private readonly Ollama $ollama,
        private readonly Governor $governor,
        private readonly Hardware $hardware,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listen(): array
    {
        $errno = 0;
        $error = '';
        $server = @\stream_socket_server(
            \sprintf('tcp://%s:%d', self::HOST, self::PORT),
            $errno,
            $error,
        );

        if (!\is_resource($server)) {
            $this->note(\sprintf('the governed endpoint could not listen (%s)', $error === '' ? 'port busy' : $error));

            return ['ok' => false, 'error' => $error, 'port' => self::PORT];
        }

        \stream_set_blocking($server, false);
        $this->server = $server;
        $this->listeningSince = \microtime(true);
        $this->note(\sprintf('listening on %s — point your agent at this instead of 11434', $this->baseUrl()));

        return ['ok' => true, 'url' => $this->baseUrl()];
    }

    public function baseUrl(): string
    {
        return \sprintf('http://%s:%d', self::HOST, self::PORT);
    }

    public function openaiBaseUrl(): string
    {
        return $this->baseUrl() . '/v1';
    }

    /** One step of the server, called from the application loop. */
    public function tick(): void
    {
        $now = \microtime(true);
        $elapsed = $this->lastTick === 0.0 ? 0.0 : $now - $this->lastTick;

        if ($now - $this->secondAt >= 1.0) {
            $this->secondAt = $now;
            $this->tokensThisSecond = 0;
        }

        $this->accept();

        foreach (\array_keys($this->clients) as $id) {
            $this->read($id);
        }

        $this->schedule();
        $this->pump($elapsed);
        $this->flush();

        $this->lastTick = $now;
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $inflight = $this->inflight;

        return [
            'listening' => $this->server !== null,
            'url' => $this->baseUrl(),
            'openai_url' => $this->openaiBaseUrl(),
            'port' => self::PORT,
            'uptime' => $this->listeningSince === 0.0 ? 0 : (int) (\microtime(true) - $this->listeningSince),
            'open_connections' => \count($this->clients),
            'queued' => \count($this->queue),
            'inflight' => $inflight === null || !isset($this->clients[$inflight]) ? null : [
                'model' => (string) ($this->clients[$inflight]['model'] ?? ''),
                'shape' => ($this->clients[$inflight]['openai'] ?? false) ? 'openai' : 'ollama',
                'seconds' => \round($this->generated, 1),
                'tokens' => (int) ($this->clients[$inflight]['tokens'] ?? 0),
            ],
            'reason' => $this->reason,
            'tokens_this_second' => $this->tokensThisSecond,
            'counters' => $this->counters,
        ];
    }

    /** @return list<array{t: float, message: string}> */
    public function log(): array
    {
        return \array_reverse($this->log);
    }

    private function note(string $message): void
    {
        $this->log[] = ['t' => \microtime(true), 'message' => $message];

        if (\count($this->log) > 200) {
            $this->log = \array_slice($this->log, -200);
        }
    }

    private function accept(): void
    {
        if ($this->server === null) {
            return;
        }

        while (\count($this->clients) < 64) {
            $socket = @\stream_socket_accept($this->server, 0);

            if (!\is_resource($socket)) {
                return;
            }

            \stream_set_blocking($socket, false);
            \stream_set_read_buffer($socket, 0);

            $peer = @\stream_socket_get_name($socket, true) ?: 'unknown';

            // The listener is bound to loopback; a client that somehow arrives
            // from elsewhere is dropped rather than served.
            if (!\str_starts_with($peer, '127.0.0.1') && !\str_starts_with($peer, '::1')) {
                @\fclose($socket);
                $this->counters['refused']++;

                continue;
            }

            $id = $this->nextId++;
            $this->clients[$id] = [
                'socket' => $socket,
                'peer' => $peer,
                'raw' => '',
                'pending' => '',
                'state' => 'reading',
                'out' => '',
                'model' => '',
                'text' => '',
                'tools' => [],
                'tokens' => 0,
                'stream' => true,
                'openai' => false,
                'streaming' => false,
                'finished' => false,
                'id' => 'chatcmpl-' . \bin2hex(\random_bytes(6)),
            ];
            $this->counters['connections']++;
        }
    }

    private function read(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $client = &$this->clients[$id];

        if ($client['state'] !== 'reading') {
            return;
        }

        $chunk = @\fread($client['socket'], 65_536);

        if ($chunk === false || ($chunk === '' && \feof($client['socket']))) {
            $this->finish($id, true);

            return;
        }

        if ($chunk === '') {
            return;
        }

        $client['raw'] .= $chunk;

        $split = \strpos($client['raw'], "\r\n\r\n");

        if ($split === false) {
            if (\strlen($client['raw']) > 262_144) {
                $this->respond($id, 431, ['error' => 'request headers too large']);
            }

            return;
        }

        $head = \substr($client['raw'], 0, $split);
        $rest = \substr($client['raw'], $split + 4);

        $lines = \explode("\r\n", $head);
        $requestLine = \array_shift($lines) ?? '';
        $headers = [];

        foreach ($lines as $line) {
            $colon = \strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $headers[\strtolower(\trim(\substr($line, 0, $colon)))] = \trim(\substr($line, $colon + 1));
        }

        if (\preg_match('#^(\S+)\s+(\S+)\s+HTTP/(\d\.\d)$#', $requestLine, $m) !== 1) {
            $this->respond($id, 400, ['error' => 'malformed request line']);

            return;
        }

        $method = \strtoupper($m[1]);
        $path = \explode('?', $m[2], 2)[0];
        $chunkedBody = \stripos($headers['transfer-encoding'] ?? '', 'chunked') !== false;
        $length = isset($headers['content-length']) && \ctype_digit($headers['content-length'])
            ? (int) $headers['content-length']
            : null;

        $rest = $client['pending'] . $rest;

        if ($length !== null) {
            if (\strlen($rest) < $length) {
                $client['pending'] = $rest;

                return;
            }

            $body = \substr($rest, 0, $length);
        } elseif ($chunkedBody) {
            $decoded = $this->decodeChunkedBody($rest);

            if ($decoded === null) {
                $client['pending'] = $rest;

                return;
            }

            $body = $decoded;
        } else {
            $body = $rest;
        }

        $client['pending'] = '';
        $client['raw'] = '';
        $client['method'] = $method;
        $client['path'] = $path;
        $client['state'] = 'routing';

        $this->route($id, $method, $path, $body);
    }

    private function route(int $id, string $method, string $path, string $body): void
    {
        // Read-only metadata: local, quick, and not what makes a fan spin.
        $meta = [
            'GET' => ['/api/version', '/api/tags', '/api/ps', '/'],
            'POST' => ['/api/show', '/api/embed', '/api/embeddings'],
        ];

        if (isset($meta[$method]) && \in_array($path, $meta[$method], true) && $path !== '/') {
            $this->forward($id, $method, $path, $body);

            return;
        }

        if ($method === 'GET' && $path === '/') {
            $this->respond($id, 200, [
                'quiesce' => 'the governed endpoint',
                'note' => 'every generation is serialised and paced by the quiet profile',
                'routes' => [
                    'GET /api/version', 'GET /api/tags', 'GET /api/ps', 'POST /api/show',
                    'POST /api/chat', 'POST /api/generate', 'GET /v1/models', 'POST /v1/chat/completions',
                ],
            ]);

            return;
        }

        if ($method === 'GET' && ($path === '/v1/models' || $path === '/models')) {
            $this->models($id);

            return;
        }

        if ($method === 'POST' && $path === '/v1/chat/completions') {
            // Decoded as objects, not arrays: see Http::open(). A tools array that
            // goes through PHP arrays comes out with `{}` rewritten as `[]`, and
            // Ollama refuses the whole request over it.
            $payload = \json_decode($body);

            if (!\is_object($payload)) {
                $this->respond($id, 400, ['error' => 'body is not JSON']);

                return;
            }

            $this->clients[$id]['openai'] = true;
            $this->clients[$id]['stream'] = (bool) ($payload->stream ?? false);
            $this->clients[$id]['model'] = (string) ($payload->model ?? '');
            $this->clients[$id]['upstream_path'] = '/api/chat';
            $this->clients[$id]['upstream'] = $this->openAiToOllama($payload);
            $this->enqueue($id);
            $this->note(\sprintf('queued /v1/chat/completions for %s', $this->clients[$id]['model']));

            return;
        }

        if ($method === 'POST' && \in_array($path, ['/api/chat', '/api/generate'], true)) {
            $payload = \json_decode($body);

            if (!\is_object($payload)) {
                $this->respond($id, 400, ['error' => 'body is not JSON']);

                return;
            }

            $this->clients[$id]['openai'] = false;
            $this->clients[$id]['stream'] = (bool) ($payload->stream ?? true);
            $this->clients[$id]['model'] = (string) ($payload->model ?? '');
            $this->clients[$id]['upstream_path'] = $path;
            $this->clients[$id]['upstream'] = $this->governed($payload);
            $this->enqueue($id);
            $this->note(\sprintf('queued %s for %s', $path, $this->clients[$id]['model'] === '' ? 'a model' : $this->clients[$id]['model']));

            return;
        }

        $this->counters['refused']++;
        $this->respond($id, 404, [
            'error' => 'this endpoint only serves the routes the governor knows how to pace',
            'routes' => [
                'GET /api/version', 'GET /api/tags', 'GET /api/ps', 'POST /api/show',
                'POST /api/chat', 'POST /api/generate', 'GET /v1/models', 'POST /v1/chat/completions',
            ],
        ]);
    }

    /**
     * Apply the profile: context, prediction limit, thread count and keep-alive,
     * all as ceilings rather than overrides.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function governed(object $payload): object
    {
        $model = (string) ($payload->model ?? '');
        $asked = \is_object($payload->options ?? null) ? (array) $payload->options : [];

        /*
         * A reasoning model counts its thinking against the same prediction
         * budget as its answer, so the profile's base cap can silence it
         * completely — measured on qwen3:30b-a3b, which spent all 1,024 tokens
         * thinking and returned nothing at all. Models that declare the
         * `thinking` capability therefore get twice the base here, and everything
         * is still held under Governor::MAX_PREDICT. A client asking for more
         * than that is clamped, and told so in the log.
         */
        $room = $this->modelThinks($model) ? 2 : 1;

        if (\is_numeric($asked['num_predict'] ?? null) && (int) $asked['num_predict'] > (int) $this->governor->current()['num_predict'] * $room) {
            $this->note(sprintf(
                'num_predict %d asked for; %d in force for %s',
                (int) $asked['num_predict'],
                (int) $this->governor->current()['num_predict'] * $room,
                $model,
            ));
        }

        $payload->options = (object) $this->governor->options($asked, $room);
        $payload->stream = true;

        $askedKeepAlive = $payload->keep_alive ?? null;
        $allowed = $this->governor->keepAlive();

        if ($askedKeepAlive !== null && (string) $askedKeepAlive !== $allowed) {
            $this->note(\sprintf('keep_alive %s asked for, %s enforced', (string) $askedKeepAlive, $allowed));
        }

        $payload->keep_alive = $allowed;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function openAiToOllama(object $payload): object
    {
        $options = [];

        if (isset($payload->max_tokens) && \is_numeric($payload->max_tokens)) {
            $options['num_predict'] = (int) $payload->max_tokens;
        }

        if (isset($payload->temperature) && \is_numeric($payload->temperature)) {
            $options['temperature'] = (float) $payload->temperature;
        }

        if (isset($payload->top_p) && \is_numeric($payload->top_p)) {
            $options['top_p'] = (float) $payload->top_p;
        }

        if (isset($payload->stop)) {
            $options['stop'] = $payload->stop;
        }

        $upstream = (object) [
            'model' => (string) ($payload->model ?? ''),
            'messages' => \is_array($payload->messages ?? null) ? $payload->messages : [],
            'stream' => true,
            'options' => (object) $options,
        ];

        if (isset($payload->tools) && \is_array($payload->tools) && $payload->tools !== []) {
            $upstream->tools = $payload->tools;
        }

        if (($payload->response_format->type ?? null) === 'json_object') {
            $upstream->format = 'json';
        }

        return $this->governed($upstream);
    }

    /** Asked of the engine, then remembered: does this model reason first? */
    private function modelThinks(string $model): bool
    {
        if ($model === '') {
            return false;
        }

        if (isset($this->thinks[$model])) {
            return $this->thinks[$model];
        }

        $capabilities = $this->ollama->show($model)['capabilities'] ?? [];

        return $this->thinks[$model] = \is_array($capabilities)
            && \in_array('thinking', $capabilities, true);
    }

    private function enqueue(int $id): void
    {
        $this->clients[$id]['state'] = 'queued';
        $this->queue[] = $id;
        $this->counters['queued']++;
    }

    /** Start the next queued generation, if the governor says we may. */
    private function schedule(): void
    {
        if ($this->inflight !== null || $this->queue === []) {
            return;
        }

        $now = \microtime(true);

        if ($now < $this->cooldownUntil) {
            $this->reason = \sprintf('cooling for another %.1fs', $this->cooldownUntil - $now);

            return;
        }

        $id = $this->queue[0];

        if (!isset($this->clients[$id])) {
            \array_shift($this->queue);

            return;
        }

        $gate = $this->governor->gate($this->hardware->gpu());

        if (!$gate['allow']) {
            $this->reason = $gate['reason'];
            $this->counters['held']++;

            return;
        }

        \array_shift($this->queue);

        $client = &$this->clients[$id];
        $path = (string) ($client['upstream_path'] ?? '/api/chat');
        $options = \is_object($client['upstream']->options ?? null) ? $client['upstream']->options : null;

        try {
            $this->upstream = $this->ollama->http()->open('POST', $path, $client['upstream'], 30.0);
        } catch (RuntimeException $e) {
            $this->respond($id, 502, ['error' => $e->getMessage()]);
            $this->counters['failed']++;

            return;
        }

        $this->inflight = $id;
        $this->generated = 0.0;
        $client['state'] = 'stream';
        $client['started'] = $now;
        $this->counters['generations']++;

        $this->note(\sprintf(
            'generating with %s (%s, ctx %d, %s threads)',
            $client['model'],
            $client['openai'] ? 'OpenAI shape' : 'Ollama shape',
            (int) ($options->num_ctx ?? 0),
            (int) ($options->num_thread ?? 0) === 0 ? 'all' : (string) ($options->num_thread ?? 0),
        ));
    }

    /**
     * Move bytes from Ollama to the client — but only while the governor allows.
     * When it does not, this deliberately reads nothing: the socket buffer fills
     * and the model stalls on its next write. That is the throttle.
     */
    private function pump(float $elapsed): void
    {
        if ($this->inflight === null || $this->upstream === null || !isset($this->clients[$this->inflight])) {
            return;
        }

        $id = $this->inflight;
        $gate = $this->governor->gate($this->hardware->gpu());
        $allow = $gate['allow'];
        $reason = $gate['reason'];

        if ($allow) {
            $budget = $this->governor->pastRequestBudget($this->generated);

            if (!$budget['allow']) {
                $allow = false;
                $reason = $budget['reason'];
                $this->cooldownUntil = \microtime(true) + 5.0;
                $this->note($budget['reason'] . ' — pausing it for 5s');
            }
        }

        if ($allow) {
            $perSecond = $this->governor->tokensPerSecond();

            if ($perSecond > 0 && $this->tokensThisSecond >= $perSecond) {
                $allow = false;
                $reason = \sprintf('paced to %d tokens/s', $perSecond);
                $this->counters['paced']++;
            }
        }

        if (!$allow) {
            $this->reason = $reason;

            return;
        }

        $bytes = $this->upstream->pump();
        $this->reason = 'going';

        if ($elapsed > 0.0) {
            // Prefill counts as busy too: it is the densest part of the work.
            $this->generated += $elapsed;
            $this->governor->markBusy($elapsed);
        }

        if ($bytes !== '') {
            $this->deliver($id, $bytes);
        }

        if ($this->upstream->finished()) {
            $this->finish($id);
        }
    }

    /** Turn upstream bytes into whatever shape the caller asked for. */
    private function deliver(int $id, string $bytes): void
    {
        $client = &$this->clients[$id];

        // An error from Ollama is not a stream. Hand the caller the real status
        // and the real body instead of 200 with an error tucked inside it.
        if (!($client['bad'] ?? false) && !$client['streaming']
            && $this->upstream !== null && $this->upstream->status !== null && $this->upstream->status !== 200) {
            $client['bad'] = true;
            $client['text'] = '';
        }

        if ($client['bad'] ?? false) {
            $client['text'] .= $bytes;

            return;
        }

        if (!$client['openai']) {
            $lines = \substr_count($bytes, "\n");
            $client['tokens'] += $lines;
            $this->tokensThisSecond += $lines;

            /*
             * A client that asked for `stream: false` gets one JSON object, not a
             * line-per-token feed. The guard reads the upstream as a stream either
             * way — that is how it paces and holds — but it must not change the
             * framing the client asked for. (It did, until the chat asked for a
             * single response and got NDJSON it could not parse.)
             */
            if ($client['stream'] === true) {
                $this->emit($id, $bytes);
            } else {
                $client['pending'] .= $bytes;
            }

            return;
        }

        $client['pending'] .= $bytes;

        while (($newline = \strpos($client['pending'], "\n")) !== false) {
            $line = \trim(\substr($client['pending'], 0, $newline));
            $client['pending'] = \substr($client['pending'], $newline + 1);

            if ($line === '') {
                continue;
            }

            $event = \json_decode($line, true);

            if (!\is_array($event)) {
                continue;
            }

            if (isset($event['error'])) {
                $client['text'] .= (string) \json_encode($event['error']);

                continue;
            }

            $message = \is_array($event['message'] ?? null) ? $event['message'] : [];
            $delta = [];

            if (isset($message['content']) && $message['content'] !== '') {
                $delta['content'] = $message['content'];
                $client['text'] .= $message['content'];
                $client['tokens']++;
                $this->tokensThisSecond++;
            }

            if (isset($message['tool_calls']) && \is_array($message['tool_calls'])) {
                $calls = $this->accumulateCalls($client, $message['tool_calls']);

                if ($calls !== []) {
                    $delta['tool_calls'] = $calls;
                    $client['tool_calls'] = true;
                }
            }

            if ($delta !== [] && $client['stream']) {
                $this->sse($id, $delta, null);
            }

            if ((bool) ($event['done'] ?? false)) {
                $client['final'] = true;
                $client['usage'] = [
                    'prompt_tokens' => (int) ($event['prompt_eval_count'] ?? 0),
                    'completion_tokens' => (int) ($event['eval_count'] ?? 0),
                    'total_tokens' => (int) ($event['prompt_eval_count'] ?? 0) + (int) ($event['eval_count'] ?? 0),
                ];

                if ($client['stream']) {
                    $this->sse($id, [], ($client['tool_calls'] ?? false) ? 'tool_calls' : 'stop');
                    $client['out'] .= "data: [DONE]\n\n";
                    $client['done_sent'] = true;
                }
            }
        }
    }

    /**
     * Ollama sends whole tool calls per event; OpenAI streams them as fragments.
     * Accumulating here means a non-streaming caller gets a usable object.
     *
     * @param array<string, mixed> $client
     * @param array<mixed> $calls
     *
     * @return list<array<string, mixed>>
     */
    private function accumulateCalls(array &$client, array $calls): array
    {
        $out = [];

        foreach ($calls as $index => $call) {
            if (!\is_array($call)) {
                continue;
            }

            $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
            $arguments = $function['arguments'] ?? '{}';
            $arguments = \is_string($arguments) ? $arguments : (\json_encode($arguments, \JSON_UNESCAPED_SLASHES) ?: '{}');
            $id = (string) ($call['id'] ?? 'call_' . $index);
            $name = (string) ($function['name'] ?? '');

            $client['tools'][$index] = [
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => $arguments],
            ];

            $out[] = [
                'index' => (int) $index,
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => $arguments],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $delta */
    private function sse(int $id, array $delta, ?string $finish): void
    {
        $client = $this->clients[$id];

        $chunk = [
            'id' => $client['id'],
            'object' => 'chat.completion.chunk',
            'created' => (int) ($client['started'] ?? \time()),
            'model' => $client['model'],
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finish,
            ]],
        ];

        $this->emit($id, 'data: ' . \json_encode($chunk, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private function models(int $id): void
    {
        $models = [];

        foreach ($this->ollama->models() as $model) {
            $models[] = [
                'id' => $model['name'],
                'object' => 'model',
                'created' => \strtotime($model['modified']) ?: \time(),
                'owned_by' => 'local',
            ];
        }

        $this->respond($id, 200, ['object' => 'list', 'data' => $models]);
    }

    private function forward(int $id, string $method, string $path, string $body): void
    {
        $payload = $body === '' ? null : \json_decode($body, true);

        try {
            $response = $this->ollama->http()->json($method, $path, \is_array($payload) ? $payload : null, 20.0);
        } catch (RuntimeException $e) {
            $this->respond($id, 502, ['error' => $e->getMessage()]);
            $this->counters['failed']++;

            return;
        }

        $this->write($id, $response['status'], $response['body'] === '' ? '{}' : $response['body']);
        $this->flush();
        $this->finish($id);
    }

    /** @param array<string, mixed> $payload */
    private function respond(int $id, int $status, array $payload): void
    {
        $body = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $this->write($id, $status, $body === false ? '{}' : $body);
        $this->flush();
        $this->finish($id);
    }

    /** Write a complete, self-delimiting response. */
    private function write(int $id, int $status, string $body): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $reasons = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            431 => 'Request Header Fields Too Large',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        $this->clients[$id]['out'] .= \sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n%s",
            $status,
            $reasons[$status] ?? 'Response',
            \strlen($body),
            $body,
        );

        $this->clients[$id]['state'] = 'done';
    }

    /** Open the chunked response used by every generated answer. */
    private function openStream(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $type = ($this->clients[$id]['openai'] ?? false) ? 'text/event-stream' : 'application/x-ndjson';

        $this->clients[$id]['out'] .= \sprintf(
            "HTTP/1.1 200 OK\r\nContent-Type: %s\r\nCache-Control: no-store\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n",
            $type,
        );
    }

    private function emit(int $id, string $bytes): void
    {
        if (!isset($this->clients[$id]) || $bytes === '') {
            return;
        }

        if (!($this->clients[$id]['streaming'] ?? false)) {
            $this->openStream($id);
            $this->clients[$id]['streaming'] = true;
        }

        $this->clients[$id]['out'] .= \sprintf("%x\r\n%s\r\n", \strlen($bytes), $bytes);
    }

    private function finish(int $id, bool $abandoned = false): void
    {
        if (!isset($this->clients[$id]) || ($this->clients[$id]['finished'] ?? false)) {
            return;
        }

        $this->clients[$id]['finished'] = true;
        $client = &$this->clients[$id];

        if ($this->inflight === $id) {
            $this->upstream?->abort();
            $this->reset();
        }

        if (!$abandoned && $client['state'] !== 'done') {
            if ($client['state'] === 'queued') {
                $this->write($id, 503, '{"error":"the governor never admitted this request"}');
            } elseif ($client['bad'] ?? false) {
                $status = $this->upstream?->status ?? 502;
                $decoded = \json_decode($client['text'], true);
                $body = \is_array($decoded)
                    ? $client['text']
                    : (\json_encode(['error' => 'ollama refused this request', 'body' => \substr($client['text'], 0, 600)]) ?: '{}');

                $this->write($id, $status >= 400 ? $status : 502, $body);
                $this->counters['failed']++;
            } elseif ($client['openai'] ?? false) {
                if ($client['stream'] ?? true) {
                    if (!($client['streaming'] ?? false)) {
                        $this->openStream($id);
                        $client['streaming'] = true;
                    }

                    if (!($client['done_sent'] ?? false)) {
                        $this->sse($id, [], ($client['tool_calls'] ?? false) ? 'tool_calls' : 'stop');
                        $client['out'] .= "data: [DONE]\n\n";
                    }

                    $client['out'] .= "0\r\n\r\n";
                } else {
                    $body = \json_encode($this->completion($id), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                    $this->write($id, 200, $body === false ? '{}' : $body);
                }
            } elseif ($client['streaming'] ?? false) {
                $client['out'] .= "0\r\n\r\n";
            } elseif ($client['pending'] !== '') {
                // The whole answer, for a client that asked for it in one piece.
                $last = '';

                foreach (\explode("\n", \trim($client['pending'])) as $line) {
                    if (\trim($line) !== '') {
                        $last = \trim($line);
                    }
                }

                $this->write($id, 200, $last === '' ? '{"done":true}' : $last);
            } else {
                $this->write($id, 200, \json_encode(['model' => $client['model'], 'done' => true]) ?: '{}');
            }
        }

        $this->flush();

        if (\is_resource($client['socket'])) {
            @\fclose($client['socket']);
        }

        unset($this->clients[$id]);

        $this->queue = \array_values(\array_filter(
            $this->queue,
            static fn (int $queued): bool => $queued !== $id,
        ));

        if ($this->inflight === $id) {
            $this->reset();
        }
    }

    /** @return array<string, mixed> */
    private function completion(int $id): array
    {
        $client = $this->clients[$id];

        $message = ['role' => 'assistant', 'content' => $client['text']];

        if (($client['tools'] ?? []) !== []) {
            $message['tool_calls'] = \array_values($client['tools']);
        }

        return [
            'id' => $client['id'],
            'object' => 'chat.completion',
            'created' => (int) ($client['started'] ?? \time()),
            'model' => $client['model'],
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => ($client['tool_calls'] ?? false) ? 'tool_calls' : 'stop',
            ]],
            'usage' => $client['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
    }

    private function reset(): void
    {
        $this->inflight = null;
        $this->upstream = null;
        $this->generated = 0.0;
    }

    private function flush(): void
    {
        foreach (\array_keys($this->clients) as $id) {
            if (!isset($this->clients[$id]) || $this->clients[$id]['out'] === '') {
                continue;
            }

            $written = @\fwrite($this->clients[$id]['socket'], $this->clients[$id]['out']);

            if ($written === false) {
                $this->finish($id, true);

                continue;
            }

            if ($written > 0) {
                $this->clients[$id]['out'] = \substr($this->clients[$id]['out'], $written);
            }
        }
    }

    /** @return string|null null when the body is not complete yet */
    private function decodeChunkedBody(string $raw): ?string
    {
        $out = '';
        $offset = 0;

        while (true) {
            $end = \strpos($raw, "\r\n", $offset);

            if ($end === false) {
                return null;
            }

            $line = \substr($raw, $offset, $end - $offset);
            $semi = \strpos($line, ';');
            $size = \hexdec(\trim($semi === false ? $line : \substr($line, 0, $semi)));
            $offset = $end + 2;

            if ($size === 0) {
                return $out;
            }

            if (\strlen($raw) < $offset + (int) $size + 2) {
                return null;
            }

            $out .= \substr($raw, $offset, (int) $size);
            $offset += (int) $size + 2;
        }
    }
}
