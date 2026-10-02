<?php

declare(strict_types=1);

namespace App;

/**
 * MCP servers, over stdio.
 *
 * A client for the reader's own servers: processes they configured, started by
 * this app, speaking newline-delimited JSON-RPC 2.0. The handshake is
 * `initialize` → `notifications/initialized` → `tools/list`, and every tool a
 * server offers is handed to the model as `mcp_<server>_<tool>`.
 *
 * Two things this class is careful about:
 *
 *  - it never blocks. Handshakes and calls are stepped from the app loop, so a
 *    server that hangs cannot freeze the window;
 *  - it treats server output as untrusted text. Tool results are passed to the
 *    model as data, and calls are logged, because a server is another program
 *    the reader installed — the app does not pretend to know what it does.
 */
final class Mcp
{
    public const int INITIALIZE_TIMEOUT = 10;

    public const int CALL_TIMEOUT = 90;

    public const int MAX_RESULT = 32_768;

    /** @var array<string, array<string, mixed>> server id => live state */
    private array $connections = [];

    private int $nextRequestId = 1;

    private ?string $calling = null;

    private string $pendingOutput = '';

    private bool $pendingOk = true;

    private float $pendingStarted = 0.0;

    public function __construct(private readonly string $configPath) {}

    public function path(): string
    {
        return $this->configPath;
    }

    /** @return array{servers: list<array<string, mixed>>} */
    public function config(): array
    {
        if (!\is_file($this->configPath)) {
            return ['servers' => []];
        }

        $decoded = \json_decode((string) @\file_get_contents($this->configPath), true);
        $servers = \is_array($decoded) ? ($decoded['servers'] ?? []) : [];

        if (!\is_array($servers)) {
            return ['servers' => []];
        }

        return ['servers' => \array_values(\array_filter($servers, '\is_array'))];
    }

    /** @return list<array<string, mixed>> */
    public function servers(): array
    {
        $enabled = [];

        foreach ($this->config()['servers'] as $server) {
            if (($server['enabled'] ?? true) === true && \is_string($server['command'] ?? null)) {
                $enabled[] = $server;
            }
        }

        return $enabled;
    }

    /** Step every live connection: handshake, read, time out. */
    public function tick(): void
    {
        $now = \microtime(true);

        foreach ($this->servers() as $server) {
            $id = (string) ($server['id'] ?? $server['command']);

            if (!isset($this->connections[$id])) {
                $this->connect($id, $server);
            }

            $connection = &$this->connections[$id];

            if ($connection['process'] === null) {
                continue;
            }

            if (!$this->pump($id, $connection)) {
                continue;
            }

            if ($connection['stage'] === 'starting') {
                $this->send($id, [
                    'jsonrpc' => '2.0',
                    'id' => $connection['id']++,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'quiesce', 'version' => '0.1'],
                    ],
                ]);
                $connection['stage'] = 'initializing';
                $connection['stage_at'] = $now;
            } elseif ($connection['stage'] === 'initializing' && $connection['initialized']) {
                $this->send($id, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => new \stdClass()]);
                $this->send($id, [
                    'jsonrpc' => '2.0',
                    'id' => $connection['id']++,
                    'method' => 'tools/list',
                    'params' => new \stdClass(),
                ]);
                $connection['stage'] = 'listing';
                $connection['stage_at'] = $now;
            } elseif ($connection['stage'] === 'listing' && $connection['listed']) {
                $connection['stage'] = 'ready';
            }

            if (\in_array($connection['stage'], ['initializing', 'listing'], true)
                && $now - $connection['stage_at'] > self::INITIALIZE_TIMEOUT) {
                $connection['error'] = 'the server did not answer ' . $connection['stage'] . ' within '
                    . self::INITIALIZE_TIMEOUT . 's';
                $this->disconnect($id);
            }

            if ($this->calling === $id && $connection['call_started'] > 0.0
                && $now - $connection['call_started'] > self::CALL_TIMEOUT) {
                $connection['error'] = 'the tool call did not answer within ' . self::CALL_TIMEOUT . 's';
                $connection['call_started'] = 0.0;
                $this->finishCall($id, false, 'the tool call timed out');
            }
        }
    }

    /**
     * Every tool from every ready server, in the shape the model expects.
     *
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->servers() as $server) {
            $id = (string) ($server['id'] ?? '');

            if (!isset($this->connections[$id]) || $this->connections[$id]['stage'] !== 'ready') {
                continue;
            }

            foreach ($this->connections[$id]['tools'] as $tool) {
                $definitions[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => self::flatName($id, (string) $tool['name']),
                        'description' => 'MCP server "' . $id . '": ' . (string) ($tool['description'] ?? $tool['name']),
                        'parameters' => \is_array($tool['inputSchema'] ?? null)
                            ? $tool['inputSchema']
                            : ['type' => 'object', 'properties' => new \stdClass()],
                    ],
                ];
            }
        }

        return $definitions;
    }

    /** Is this a name one of our servers can serve? */
    public function owns(string $flatName): bool
    {
        return $this->resolve($flatName) !== null;
    }

    /** @return array{ok: bool, error?: string} */
    public function start(string $flatName, array $arguments): array
    {
        $target = $this->resolve($flatName);

        if ($target === null) {
            return ['ok' => false, 'error' => 'no MCP server offers ' . $flatName];
        }

        [$serverId, $tool] = $target;
        $connection = $this->connections[$serverId] ?? null;

        if ($connection === null || $connection['stage'] !== 'ready') {
            return ['ok' => false, 'error' => 'the MCP server "' . $serverId . '" is not ready yet'];
        }

        if ($this->calling !== null) {
            return ['ok' => false, 'error' => 'another MCP call is already running'];
        }

        $arguments = $arguments === [] ? new \stdClass() : $arguments;

        $this->send($serverId, [
            'jsonrpc' => '2.0',
            'id' => $connection['id']++,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        $this->calling = $serverId;
        $this->pendingOutput = '';
        $this->pendingOk = true;
        $this->pendingStarted = \microtime(true);
        $this->connections[$serverId]['call_started'] = $this->pendingStarted;

        return ['ok' => true];
    }

    /**
     * @return array{running: bool, output: string, ok: bool}
     */
    public function poll(): array
    {
        if ($this->calling === null) {
            return ['running' => false, 'output' => $this->pendingOutput, 'ok' => $this->pendingOk];
        }

        return ['running' => true, 'output' => '', 'ok' => true];
    }

    public function stop(): void
    {
        if ($this->calling !== null) {
            $this->finishCall($this->calling, false, 'the call was stopped');
        }
    }

    /** @return list<array<string, mixed>> */
    public function status(): array
    {
        $status = [];

        foreach ($this->servers() as $server) {
            $id = (string) ($server['id'] ?? '');
            $connection = $this->connections[$id] ?? null;

            $status[] = [
                'id' => $id,
                'command' => (string) ($server['command'] ?? ''),
                'network' => ($server['network'] ?? false) === true,
                'stage' => $connection['stage'] ?? 'not started',
                'tools' => \array_map(
                    static fn (array $tool): string => (string) $tool['name'],
                    $connection['tools'] ?? [],
                ),
                'error' => $connection['error'] ?? '',
            ];
        }

        return $status;
    }

    public function shutdown(): void
    {
        foreach (\array_keys($this->connections) as $id) {
            $this->disconnect($id);
        }
    }

    /** `mcp_<server>_<tool>`, flattened to something a model can name. */
    public static function flatName(string $server, string $tool): string
    {
        $clean = static fn (string $value): string => (string) \preg_replace('/[^A-Za-z0-9]+/', '_', $value);

        return 'mcp_' . \trim($clean($server), '_') . '_' . \trim($clean($tool), '_');
    }

    /** @return array{0: string, 1: string}|null */
    private function resolve(string $flatName): ?array
    {
        foreach ($this->servers() as $server) {
            $id = (string) ($server['id'] ?? '');

            foreach ($this->connections[$id]['tools'] ?? [] as $tool) {
                if (self::flatName($id, (string) $tool['name']) === $flatName) {
                    return [$id, (string) $tool['name']];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $server */
    private function connect(string $id, array $server): void
    {
        $command = (string) $server['command'];
        $arguments = \is_array($server['args'] ?? null) ? \array_map('\strval', $server['args']) : [];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = [
            'PATH' => (string) (\getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (\getenv('HOME') ?: '/tmp'),
            'LANG' => (string) (\getenv('LANG') ?: 'C.UTF-8'),
        ];

        foreach (\is_array($server['env'] ?? null) ? $server['env'] : [] as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }

        $process = @\proc_open([$command, ...$arguments], $descriptors, $pipes, null, $environment);

        if (!\is_resource($process)) {
            $this->connections[$id] = [
                'process' => null, 'pipes' => [], 'buffer' => '', 'stage' => 'failed',
                'tools' => [], 'id' => 1, 'initialized' => false, 'listed' => false,
                'stage_at' => \microtime(true), 'call_started' => 0.0,
                'error' => 'could not start "' . $command . '"',
            ];

            return;
        }

        foreach ([1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], false);
        }

        \stream_set_blocking($pipes[0], false);

        $this->connections[$id] = [
            'process' => $process,
            'pipes' => $pipes,
            'buffer' => '',
            'stage' => 'starting',
            'tools' => [],
            'id' => 1,
            'initialized' => false,
            'listed' => false,
            'stage_at' => \microtime(true),
            'call_started' => 0.0,
            'error' => '',
        ];
    }

    /** @param array<string, mixed> $connection */
    private function pump(string $id, array &$connection): bool
    {
        if (!\is_resource($connection['process'])) {
            return false;
        }

        $stdout = @\fread($connection['pipes'][1], 65_536);

        if (\is_string($stdout) && $stdout !== '') {
            $connection['buffer'] .= $stdout;
        }

        while (($newline = \strpos($connection['buffer'], "\n")) !== false) {
            $line = \trim(\substr($connection['buffer'], 0, $newline));
            $connection['buffer'] = \substr($connection['buffer'], $newline + 1);

            if ($line !== '') {
                $this->receive($id, $connection, $line);
            }
        }

        $status = \proc_get_status($connection['process']);

        if (($status['running'] ?? false) === false) {
            $connection['error'] = $connection['error'] === ''
                ? 'the server exited (code ' . (string) ($status['exitcode'] ?? '?') . ')'
                : $connection['error'];
            $this->disconnect($id);

            return false;
        }

        return true;
    }

    /**
     * JSON objects arrive as empty PHP arrays, and re-encoding them turns
     * `"properties": {}` into `"properties": []` — which is not a valid schema,
     * and which Ollama rejects outright with "Value looks like object, but can't
     * find closing '}' symbol". So the schema is put back the way it came.
     */
    private static function schema(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return new \stdClass();
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::schema($item);
        }

        return $out;
    }

    /** @param array<string, mixed> $connection */
    private function receive(string $id, array &$connection, string $line): void
    {
        $message = \json_decode($line, true);

        if (!\is_array($message)) {
            return;
        }

        $result = \is_array($message['result'] ?? null) ? $message['result'] : null;

        if ($connection['stage'] === 'initializing' && $result !== null) {
            $connection['initialized'] = true;

            return;
        }

        if ($connection['stage'] === 'listing' && $result !== null) {
            $tools = [];

            foreach (\is_array($result['tools'] ?? null) ? $result['tools'] : [] as $tool) {
                if (\is_array($tool) && \is_string($tool['name'] ?? null)) {
                    $tool['inputSchema'] = self::schema($tool['inputSchema'] ?? []);
                    $tools[] = $tool;
                }
            }

            $connection['tools'] = $tools;
            $connection['listed'] = true;

            return;
        }

        if ($this->calling === $id) {
            if ($result !== null) {
                $this->finishCall($id, true, self::readable($result));
            } else {
                $error = $message['error']['message'] ?? 'the server returned an error';
                $this->finishCall($id, false, (string) $error);
            }
        }
    }

    private function finishCall(string $id, bool $ok, string $output): void
    {
        $this->pendingOutput = \substr($output, 0, self::MAX_RESULT);
        $this->pendingOk = $ok;
        $this->calling = null;

        if (isset($this->connections[$id])) {
            $this->connections[$id]['call_started'] = 0.0;
        }
    }

    /** @param array<string, mixed> $payload */
    private function send(string $id, array $payload): void
    {
        $connection = $this->connections[$id] ?? null;

        if ($connection === null || !\is_resource($connection['pipes'][0] ?? null)) {
            return;
        }

        $json = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            @\fwrite($connection['pipes'][0], $json . "\n");
        }
    }

    private function disconnect(string $id): void
    {
        $connection = $this->connections[$id] ?? null;

        if ($connection === null) {
            return;
        }

        foreach ($connection['pipes'] as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        if (\is_resource($connection['process'])) {
            @\proc_terminate($connection['process']);
            @\proc_close($connection['process']);
        }

        unset($this->connections[$id]);
    }

    /**
     * MCP tool results are content parts; the model wants text.
     *
     * @param array<string, mixed> $result
     */
    private static function readable(array $result): string
    {
        $parts = [];

        foreach (\is_array($result['content'] ?? null) ? $result['content'] : [] as $part) {
            if (!\is_array($part)) {
                continue;
            }

            if (($part['type'] ?? '') === 'text' && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            } else {
                $parts[] = '[' . (string) ($part['type'] ?? 'content') . ' part]';
            }
        }

        if ($parts === []) {
            $parts[] = (string) \json_encode($result, \JSON_UNESCAPED_SLASHES);
        }

        return \implode("\n", $parts);
    }
}
