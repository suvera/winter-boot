<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\stream;

use dev\winterframework\web\client\RestClientException;

/**
 * Incremental HTTP/1.1 response parser: feed it bytes as they arrive from
 * the socket and it reports the status line and headers once, then every
 * piece of (de-chunked, decoded) body as soon as it is complete.
 *
 * Supports `Transfer-Encoding: chunked` (chunk extensions and trailers are
 * skipped), `Content-Length`, close-delimited bodies, interim 1xx responses
 * (skipped), bodiless responses (HEAD, 204, 304) and `Content-Encoding:
 * gzip`/`deflate` (decoded incrementally when the server ignores
 * `Accept-Encoding: identity`). Header blocks and chunk-size lines are
 * capped so a hostile server cannot grow memory without bound.
 *
 * Pure logic, no I/O: unit-testable by feeding arbitrary byte splits.
 */
final class HttpResponseStreamParser {
    public const DEFAULT_MAX_HEADER_BYTES = 65536;
    private const MAX_CHUNK_LINE_BYTES = 4096;

    private const S_HEAD = 0;
    private const S_LENGTH = 1;
    private const S_CHUNK_SIZE = 2;
    private const S_CHUNK_DATA = 3;
    private const S_CHUNK_CRLF = 4;
    private const S_TRAILERS = 5;
    private const S_CLOSE = 6;
    private const S_DONE = 7;

    private int $state = self::S_HEAD;
    private string $buffer = '';
    private int $remaining = 0;
    private int $status = 0;
    /** @var array<string, string[]> */
    private array $headers = [];
    private bool $aborted = false;
    /** @var \InflateContext|null */
    private mixed $inflate = null;
    /** @var callable(int, array<string, string[]>): void */
    private $onHead;
    /** @var callable(string): bool */
    private $onBody;

    /**
     * @param callable(int, array<string, string[]>): void $onHead called once with the final status and headers
     * @param callable(string): bool $onBody called per decoded body piece; false stops parsing (abort)
     * @param bool $headRequest the request was HEAD: the response has no body whatever its headers say
     */
    public function __construct(
        callable $onHead,
        callable $onBody,
        private bool $headRequest = false,
        private int $maxHeaderBytes = self::DEFAULT_MAX_HEADER_BYTES
    ) {
        $this->onHead = $onHead;
        $this->onBody = $onBody;
    }

    /**
     * Feeds the next bytes read from the connection.
     *
     * @return bool false once the body consumer asked to stop (abort)
     * @throws RestClientException on a malformed or oversized response
     */
    public function feed(string $bytes): bool {
        if ($this->aborted) {
            return false;
        }
        if ($this->state === self::S_DONE) {
            return true;
        }
        $this->buffer .= $bytes;
        while ($this->buffer !== '' || $this->state === self::S_TRAILERS) {
            $progress = match ($this->state) {
                self::S_HEAD => $this->parseHead(),
                self::S_LENGTH => $this->parseLength(),
                self::S_CHUNK_SIZE => $this->parseChunkSize(),
                self::S_CHUNK_DATA => $this->parseChunkData(),
                self::S_CHUNK_CRLF => $this->parseChunkCrlf(),
                self::S_TRAILERS => $this->parseTrailers(),
                self::S_CLOSE => $this->parseClose(),
                default => false,
            };
            if ($this->aborted) {
                return false;
            }
            if (!$progress || $this->state === self::S_DONE) {
                break;
            }
        }
        if ($this->state === self::S_DONE) {
            $this->buffer = '';
        }
        return true;
    }

    /**
     * The connection reached EOF. Completes a close-delimited body and
     * fails when the response was cut short.
     *
     * @throws RestClientException when the response is incomplete
     */
    public function finish(): void {
        if ($this->aborted || $this->state === self::S_DONE) {
            return;
        }
        if ($this->state === self::S_CLOSE) {
            $this->flushInflate();
            $this->state = self::S_DONE;
            return;
        }
        throw new RestClientException(
            $this->state === self::S_HEAD
                ? 'Connection closed before the response headers were received'
                : 'Connection closed before the response body was complete'
        );
    }

    public function isComplete(): bool {
        return $this->state === self::S_DONE;
    }

    public function isAborted(): bool {
        return $this->aborted;
    }

    public function hasHead(): bool {
        return $this->state !== self::S_HEAD;
    }

    public function getStatus(): int {
        return $this->status;
    }

    /** @return array<string, string[]> */
    public function getHeaders(): array {
        return $this->headers;
    }

    private function parseHead(): bool {
        $end = strpos($this->buffer, "\r\n\r\n");
        $sepLen = 4;
        $lfEnd = strpos($this->buffer, "\n\n");
        if ($lfEnd !== false && ($end === false || $lfEnd < $end)) {
            $end = $lfEnd;
            $sepLen = 2;
        }
        if ($end === false) {
            if (strlen($this->buffer) > $this->maxHeaderBytes) {
                throw new RestClientException('Response headers exceed ' . $this->maxHeaderBytes . ' bytes');
            }
            return false;
        }
        if ($end > $this->maxHeaderBytes) {
            throw new RestClientException('Response headers exceed ' . $this->maxHeaderBytes . ' bytes');
        }
        $block = substr($this->buffer, 0, $end);
        $this->buffer = (string)substr($this->buffer, $end + $sepLen);

        $lines = preg_split('/\r?\n/', $block) ?: [];
        $statusLine = (string)array_shift($lines);
        if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})(?:\s|$)#', $statusLine, $m)) {
            throw new RestClientException('Malformed HTTP status line in response');
        }
        $status = (int)$m[1];
        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            if ($name === '') {
                continue;
            }
            $headers[$name][] = trim(substr($line, $pos + 1));
        }

        // Interim responses (100 Continue, 103 Early Hints): the real one follows.
        if ($status >= 100 && $status < 200 && $status !== 101) {
            return true;
        }

        $this->status = $status;
        $this->startBody($headers);
        ($this->onHead)($this->status, $this->headers);
        return true;
    }

    /** @param array<string, string[]> $headers */
    private function startBody(array $headers): void {
        // Framing comes from the wire headers, read before any are dropped.
        $te = strtolower((string)self::lastValue($headers, 'Transfer-Encoding'));
        $length = self::lastValue($headers, 'Content-Length');
        $encoding = strtolower((string)self::lastValue($headers, 'Content-Encoding'));
        if (in_array($encoding, ['gzip', 'x-gzip', 'deflate'], true)) {
            if (!function_exists('inflate_init')) {
                throw new RestClientException('Response is ' . $encoding . '-encoded but zlib is not available');
            }
            $ctx = inflate_init($encoding === 'deflate' ? ZLIB_ENCODING_DEFLATE : ZLIB_ENCODING_GZIP);
            if ($ctx === false) {
                throw new RestClientException('Could not initialise response decoding');
            }
            $this->inflate = $ctx;
            // The caller sees decoded bytes, so the wire framing headers no longer apply.
            $headers = self::without($headers, 'Content-Encoding', 'Content-Length');
        }
        $this->headers = $headers;

        if ($this->headRequest || $this->status === 204 || $this->status === 304 || $this->status === 101) {
            $this->state = self::S_DONE;
            return;
        }
        if ($te !== '') {
            $codings = array_map('trim', explode(',', $te));
            if (end($codings) === 'chunked') {
                $this->state = self::S_CHUNK_SIZE;
                return;
            }
            $this->state = self::S_CLOSE;
            return;
        }
        if ($length !== null) {
            if (!preg_match('/^\d{1,18}$/', $length)) {
                throw new RestClientException('Malformed Content-Length in response');
            }
            $this->remaining = (int)$length;
            $this->state = $this->remaining === 0 ? self::S_DONE : self::S_LENGTH;
            return;
        }
        $this->state = self::S_CLOSE;
    }

    private function parseLength(): bool {
        $take = substr($this->buffer, 0, $this->remaining);
        $this->buffer = (string)substr($this->buffer, strlen($take));
        $this->remaining -= strlen($take);
        $this->emit($take);
        if (!$this->aborted && $this->remaining === 0) {
            $this->flushInflate();
            $this->state = self::S_DONE;
        }
        return true;
    }

    private function parseChunkSize(): bool {
        $pos = strpos($this->buffer, "\n");
        if ($pos === false) {
            if (strlen($this->buffer) > self::MAX_CHUNK_LINE_BYTES) {
                throw new RestClientException('Malformed chunked response (chunk size line too long)');
            }
            return false;
        }
        $line = rtrim(substr($this->buffer, 0, $pos), "\r");
        $this->buffer = (string)substr($this->buffer, $pos + 1);
        $sizeHex = trim(explode(';', $line, 2)[0]);
        if (!preg_match('/^[0-9A-Fa-f]{1,15}$/', $sizeHex)) {
            throw new RestClientException('Malformed chunked response (bad chunk size)');
        }
        $size = (int)hexdec($sizeHex);
        if ($size === 0) {
            $this->state = self::S_TRAILERS;
            return true;
        }
        $this->remaining = $size;
        $this->state = self::S_CHUNK_DATA;
        return true;
    }

    private function parseChunkData(): bool {
        $take = substr($this->buffer, 0, $this->remaining);
        $this->buffer = (string)substr($this->buffer, strlen($take));
        $this->remaining -= strlen($take);
        $this->emit($take);
        if ($this->remaining === 0) {
            $this->state = self::S_CHUNK_CRLF;
        }
        return true;
    }

    private function parseChunkCrlf(): bool {
        if ($this->buffer[0] === "\n") {
            $this->buffer = (string)substr($this->buffer, 1);
            $this->state = self::S_CHUNK_SIZE;
            return true;
        }
        if (strlen($this->buffer) < 2) {
            if ($this->buffer !== "\r") {
                throw new RestClientException('Malformed chunked response (missing chunk terminator)');
            }
            return false;
        }
        if (!str_starts_with($this->buffer, "\r\n")) {
            throw new RestClientException('Malformed chunked response (missing chunk terminator)');
        }
        $this->buffer = (string)substr($this->buffer, 2);
        $this->state = self::S_CHUNK_SIZE;
        return true;
    }

    private function parseTrailers(): bool {
        // Trailer fields are not exposed: skip lines until the empty one.
        while (true) {
            $pos = strpos($this->buffer, "\n");
            if ($pos === false) {
                if (strlen($this->buffer) > $this->maxHeaderBytes) {
                    throw new RestClientException('Response trailers exceed ' . $this->maxHeaderBytes . ' bytes');
                }
                return false;
            }
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = (string)substr($this->buffer, $pos + 1);
            if ($line === '') {
                $this->flushInflate();
                if (!$this->aborted) {
                    $this->state = self::S_DONE;
                }
                return true;
            }
        }
    }

    private function parseClose(): bool {
        $data = $this->buffer;
        $this->buffer = '';
        $this->emit($data);
        return true;
    }

    private function emit(string $data): void {
        if ($data === '' || $this->aborted) {
            return;
        }
        if ($this->inflate !== null) {
            $decoded = @inflate_add($this->inflate, $data, ZLIB_SYNC_FLUSH);
            if ($decoded === false) {
                throw new RestClientException('Could not decode the compressed response body');
            }
            $data = $decoded;
            if ($data === '') {
                return;
            }
        }
        if (($this->onBody)($data) === false) {
            $this->aborted = true;
        }
    }

    private function flushInflate(): void {
        if ($this->inflate === null || $this->aborted) {
            return;
        }
        $rest = @inflate_add($this->inflate, '', ZLIB_FINISH);
        $this->inflate = null;
        if ($rest !== false && $rest !== '' && ($this->onBody)($rest) === false) {
            $this->aborted = true;
        }
    }

    /** @param array<string, string[]> $headers */
    private static function lastValue(array $headers, string $name): ?string {
        $found = null;
        foreach ($headers as $key => $values) {
            if (strcasecmp((string)$key, $name) === 0 && $values !== []) {
                $found = (string)end($values);
            }
        }
        return $found;
    }

    /**
     * @param array<string, string[]> $headers
     * @return array<string, string[]>
     */
    private static function without(array $headers, string ...$names): array {
        foreach (array_keys($headers) as $key) {
            foreach ($names as $name) {
                if (strcasecmp((string)$key, $name) === 0) {
                    unset($headers[$key]);
                }
            }
        }
        return $headers;
    }
}
