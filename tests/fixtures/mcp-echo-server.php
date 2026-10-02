<?php

declare(strict_types=1);

/**
 * A minimal MCP server, for tests: newline-delimited JSON-RPC 2.0 over stdio.
 *
 * Real enough to be worth testing against — it handshakes, lists tools, answers
 * a call, reports an error for an unknown tool, and has one tool that never
 * answers, so the client's timeout path is exercised rather than assumed.
 */

$input = \fopen('php://stdin', 'rb');

if ($input === false) {
    exit(1);
}

$respond = static function (array $payload): void {
    \fwrite(\STDOUT, \json_encode($payload, \JSON_UNESCAPED_SLASHES) . "\n");
};

while (($line = \fgets($input)) !== false) {
    $message = \json_decode(\trim($line), true);

    if (!\is_array($message)) {
        continue;
    }

    $method = (string) ($message['method'] ?? '');
    $id = $message['id'] ?? null;

    if ($method === 'initialize') {
        $respond([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'serverInfo' => ['name' => 'echo', 'version' => '1.0'],
            ],
        ]);

        continue;
    }

    if ($method === 'notifications/initialized') {
        continue;
    }

    if ($method === 'tools/list') {
        $respond([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'echo',
                        'description' => 'Echo text back',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => ['text' => ['type' => 'string']],
                            'required' => ['text'],
                        ],
                    ],
                    [
                        'name' => 'never-answers',
                        'description' => 'Never answers, for the timeout path',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                    ],
                ],
            ],
        ]);

        continue;
    }

    if ($method === 'tools/call') {
        $name = (string) ($message['params']['name'] ?? '');
        $arguments = \is_array($message['params']['arguments'] ?? null) ? $message['params']['arguments'] : [];

        if ($name === 'echo') {
            $respond([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => ['content' => [['type' => 'text', 'text' => 'echo: ' . (string) ($arguments['text'] ?? '')]]],
            ]);

            continue;
        }

        if ($name === 'never-answers') {
            continue;
        }

        $respond([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'no tool called ' . $name],
        ]);

        continue;
    }

    if ($id !== null) {
        $respond([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'unknown method ' . $method],
        ]);
    }
}
