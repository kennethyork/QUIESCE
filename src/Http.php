<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Minimal HTTP/1.1 client over raw PHP streams.
 *
 * Why not curl:
 *  - a compiled Boson binary carries a fixed extension set, and streams are core PHP;
 *  - the quiet governor throttles a generation by *not reading* the socket, and a
 *    blocking client would hide that decision inside a syscall.
 *
 * Loopback only. There is no code path in this application that opens a socket to
 * anything but 127.0.0.1, and this constructor is the single place where that is
 * enforced.
 */
final class Http
{
    /** @var list<string> */
    public const array LOOPBACK = ['127.0.0.1', 'localhost', '::1'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {
        if (!\in_array($host, self::LOOPBACK, true)) {
            throw new RuntimeException(\sprintf('refused: "%s" is not loopback', $host));
        }
    }

    public function url(string $path): string
    {
        return \sprintf('http://%s:%d%s', $this->host, $this->port, $path);
    }

    /**
     * A short, complete request: /api/version, /api/tags, /api/show, /api/ps.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array{status: int, json: array<mixed>|null, body: string}
     */
    public function json(string $method, string $path, array|object|null $payload = null, float $timeout = 10.0): array
    {
        $stream = $this->open($method, $path, $payload, $timeout);
        $body = '';
        $deadline = \microtime(true) + $timeout;

        while (!$stream->finished()) {
            $body .= $stream->pump();

            if ($stream->finished()) {
                break;
            }

            if (\microtime(true) >= $deadline) {
                $stream->close();

                throw new RuntimeException(\sprintf('%s %s timed out after %.1fs', $method, $path, $timeout));
            }

            \usleep(2_000);
        }

        $stream->close();

        /** @var array<mixed>|null $decoded */
        $decoded = $body === '' ? null : \json_decode($body, true);

        return [
            'status' => $stream->status ?? 0,
            'json' => \is_array($decoded) ? $decoded : null,
            'body' => $body,
        ];
    }

    /**
     * Open a request and hand back a stream that is stepped by the caller.
     *
     * An object, not an array: `json_decode($body)` with objects preserves the difference
     * between `{}` and `[]`, and a schema that loses it is a schema the model server
     * rejects with "Value looks like object, but can't find closing '}' symbol".
     *
     * @param array<string, mixed>|object|null $payload
     */
    public function open(string $method, string $path, array|object|null $payload = null, float $timeout = 10.0): HttpStream
    {
        $errno = 0;
        $error = '';

        $socket = @\stream_socket_client(
            \sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $error,
            $timeout,
        );

        if (!\is_resource($socket)) {
            throw new RuntimeException(\sprintf(
                'cannot reach %s:%d (%s)',
                $this->host,
                $this->port,
                $error === '' ? 'no route' : $error,
            ));
        }

        \stream_set_blocking($socket, false);
        \stream_set_read_buffer($socket, 0);

        $body = $payload === null ? '' : \json_encode($payload, \JSON_THROW_ON_ERROR);

        $head = \sprintf(
            "%s %s HTTP/1.1\r\nHost: %s:%d\r\nConnection: close\r\nAccept: application/x-ndjson, application/json\r\n",
            $method,
            $path,
            $this->host,
            $this->port,
        );

        if ($payload !== null) {
            $head .= "Content-Type: application/json\r\nContent-Length: " . \strlen($body) . "\r\n";
        }

        self::writeAll($socket, $head . "\r\n" . $body, $timeout);

        return new HttpStream($socket);
    }

    /**
     * Write a whole request, tolerating a socket that is not ready yet.
     *
     * @param resource $socket
     */
    private static function writeAll($socket, string $bytes, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $written = @\fwrite($socket, \substr($bytes, $offset));

            if ($written === false) {
                throw new RuntimeException('write failed');
            }

            $offset += $written;

            if ($offset >= $length) {
                return;
            }

            if (\microtime(true) >= $deadline) {
                throw new RuntimeException('write timed out');
            }

            $read = null;
            $write = [$socket];
            $except = null;

            @\stream_select($read, $write, $except, 0, 50_000);
        }
    }
}
