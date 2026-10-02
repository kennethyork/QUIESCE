<?php

declare(strict_types=1);

namespace App;

/**
 * A response being read a piece at a time.
 *
 * `pump()` returns whatever body bytes have arrived and never blocks for longer
 * than one read: when it returns an empty string the caller is expected to come
 * back on the next loop tick. That is what makes throttling possible — the
 * governor simply does not call `pump()` while it is holding, the socket buffer
 * fills, and the model's own write blocks. Nothing is killed; the generation
 * stalls, which is exactly the intent.
 */
final class HttpStream
{
    /** @var resource|null */
    private $socket;

    public ?int $status = null;

    /** @var array<string, string> */
    public array $meta = [];

    private string $head = '';

    private bool $parsed = false;

    private bool $chunked = false;

    /** Bytes of a non-chunked body still expected, or -1 when the body ends at EOF. */
    private int $remaining = -1;

    private int $chunkLeft = 0;

    /** 'size' while reading a chunk-size line, 'crlf' while skipping the terminator. */
    private string $chunkState = 'size';

    private string $pending = '';

    private bool $finished = false;

    /** @param resource $socket */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    /** Body bytes decoded since the last call. */
    public function pump(): string
    {
        $out = '';

        while (true) {
            $out .= $this->decode();

            if ($this->finished || $this->socket === null) {
                break;
            }

            $read = @\fread($this->socket, 65_536);

            if ($read === false || $read === '') {
                if (\is_resource($this->socket) && \feof($this->socket)) {
                    $this->finishAtEof();
                }

                break;
            }

            $this->pending .= $read;
        }

        return $out;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    /** True once the response headers have arrived and the status is known. */
    public function started(): bool
    {
        return $this->parsed;
    }

    public function close(): void
    {
        if ($this->socket !== null && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }

        $this->socket = null;
    }

    /** Cancel a response mid-flight: the caller stops reading, the server sees a closed pipe. */
    public function abort(): void
    {
        $this->finished = true;
        $this->close();
    }

    private function decode(): string
    {
        if (!$this->parsed) {
            $end = \strpos($this->pending, "\r\n\r\n");

            if ($end === false) {
                $this->head .= $this->pending;
                $this->pending = '';

                return '';
            }

            $this->head .= \substr($this->pending, 0, $end);
            $this->pending = \substr($this->pending, $end + 4);
            $this->parseHead();
            $this->parsed = true;

            if ($this->status !== null && ($this->status < 200 || $this->status >= 300) && !$this->chunked && $this->remaining === -1) {
                // an error we still want to read to the end of
                $this->remaining = -1;
            }
        }

        if ($this->finished) {
            return '';
        }

        $out = '';

        if ($this->chunked) {
            while (true) {
                if ($this->chunkLeft > 0) {
                    if ($this->pending === '') {
                        break;
                    }

                    $take = \min($this->chunkLeft, \strlen($this->pending));
                    $out .= \substr($this->pending, 0, $take);
                    $this->pending = \substr($this->pending, $take);
                    $this->chunkLeft -= $take;

                    if ($this->chunkLeft === 0) {
                        $this->chunkState = 'crlf';
                    }

                    continue;
                }

                // Every chunk's data is followed by a CRLF that belongs to the
                // framing, not to the body. Skipping it is the difference
                // between reading all the way to the terminator and stopping at
                // the first chunk — which is exactly the bug this test caught.
                if ($this->chunkState === 'crlf') {
                    if (\strlen($this->pending) < 2) {
                        break;
                    }

                    $this->pending = \substr($this->pending, 2);
                    $this->chunkState = 'size';

                    continue;
                }

                $end = \strpos($this->pending, "\r\n");

                if ($end === false) {
                    break;
                }

                $line = \substr($this->pending, 0, $end);
                $this->pending = \substr($this->pending, $end + 2);
                $semi = \strpos($line, ';');
                $size = (int) \hexdec(\trim($semi === false ? $line : \substr($line, 0, $semi)));

                if ($size === 0) {
                    $this->finished = true;
                    $this->close();

                    break;
                }

                $this->chunkLeft = $size;
                $this->chunkState = 'data';
            }

            return $out;
        }

        if ($this->pending !== '') {
            if ($this->remaining >= 0) {
                $take = \min($this->remaining, \strlen($this->pending));
                $out = \substr($this->pending, 0, $take);
                $this->pending = \substr($this->pending, $take);
                $this->remaining -= $take;

                if ($this->remaining === 0) {
                    $this->finished = true;
                    $this->close();
                }
            } else {
                $out = $this->pending;
                $this->pending = '';
            }
        }

        return $out;
    }

    private function finishAtEof(): void
    {
        $this->finished = true;
        $this->close();
    }

    private function parseHead(): void
    {
        $lines = \explode("\r\n", \trim($this->head));
        $status = \array_shift($lines);

        if ($status !== null && \preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $status, $m) === 1) {
            $this->status = (int) $m[1];
        }

        foreach ($lines as $line) {
            $colon = \strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $this->meta[\strtolower(\trim(\substr($line, 0, $colon)))] = \trim(\substr($line, $colon + 1));
        }

        $transfer = $this->meta['transfer-encoding'] ?? '';
        $this->chunked = \stripos($transfer, 'chunked') !== false;

        if (!$this->chunked && isset($this->meta['content-length']) && \ctype_digit($this->meta['content-length'])) {
            $this->remaining = (int) $this->meta['content-length'];
        }
    }
}
