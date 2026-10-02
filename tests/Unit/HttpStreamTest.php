<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\HttpStream;
use PHPUnit\Framework\TestCase;

/**
 * The framing is where a hand-rolled client usually goes wrong, so the three
 * shapes Ollama actually sends are covered on a socket pair: chunked, a known
 * length, and a body that ends when the connection does.
 */
final class HttpStreamTest extends TestCase
{
    /** @return array{0: HttpStream, 1: resource} */
    private function stream(string $bytes): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$ours, $theirs] = $pair;
        \stream_set_blocking($ours, false);
        \fwrite($theirs, $bytes);

        return [new HttpStream($ours), $theirs];
    }

    public function testItReadsAChunkedBody(): void
    {
        $first = '{"a":1}' . "\n";
        $second = '{"message":{"content":"hi"}}' . "\n";

        [$stream] = $this->stream(
            "HTTP/1.1 200 OK\r\nContent-Type: application/x-ndjson\r\nTransfer-Encoding: chunked\r\n\r\n"
            . \dechex(\strlen($first)) . "\r\n" . $first . "\r\n"
            . \dechex(\strlen($second)) . "\r\n" . $second . "\r\n"
            . "0\r\n\r\n"
        );

        $body = '';

        for ($i = 0; $i < 20 && !$stream->finished(); $i++) {
            $body .= $stream->pump();
        }

        self::assertSame(200, $stream->status);
        self::assertSame($first . $second, $body);
        self::assertTrue($stream->finished());
    }

    public function testItReadsABodyWithAKnownLength(): void
    {
        [$stream] = $this->stream("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello and more");

        $body = '';

        for ($i = 0; $i < 20 && !$stream->finished(); $i++) {
            $body .= $stream->pump();
        }

        self::assertSame('hello', $body, 'it stops at the length even when more bytes are waiting');
        self::assertTrue($stream->finished());
    }

    public function testABodyThatEndsAtEof(): void
    {
        [$stream, $theirs] = $this->stream("HTTP/1.1 200 OK\r\n\r\nunterminated");
        \fclose($theirs);

        $body = '';

        for ($i = 0; $i < 20 && !$stream->finished(); $i++) {
            $body .= $stream->pump();
        }

        self::assertSame('unterminated', $body);
        self::assertTrue($stream->finished());
    }

    public function testAnErrorStatusIsReportedWithItsBody(): void
    {
        $payload = '{"error":"no such model"}';

        [$stream, $theirs] = $this->stream(
            "HTTP/1.1 404 Not Found\r\nContent-Length: " . \strlen($payload) . "\r\n\r\n" . $payload
        );
        \fclose($theirs);

        $body = '';

        for ($i = 0; $i < 20 && !$stream->finished(); $i++) {
            $body .= $stream->pump();
        }

        self::assertSame(404, $stream->status);
        self::assertSame($payload, $body);
    }

    public function testHeadersAreAvailableBeforeTheBodyIs(): void
    {
        [$stream, $theirs] = $this->stream("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");

        $stream->pump();

        self::assertSame(200, $stream->status);
        self::assertSame('chunked', \strtolower($stream->meta['transfer-encoding'] ?? ''));
        self::assertFalse($stream->finished());

        \fclose($theirs);
    }
}
