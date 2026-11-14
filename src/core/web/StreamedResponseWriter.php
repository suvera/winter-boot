<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\web\format\AbstractResponseRenderer;
use dev\winterframework\io\stream\ChunkedOutputStream;
use dev\winterframework\io\stream\HttpOutputStream;
use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\http\ResponseWriter;

/**
 * {@see ResponseWriter} of one streamed response, driven by the dispatcher:
 * the head (status, headers, cookies) is written with the first chunk, and
 * end() completes the response exactly once. Swoole streams send every
 * write as one HTTP chunk; other output streams get write() + flush().
 */
final class StreamedResponseWriter implements ResponseWriter {
    private bool $committed = false;
    private bool $failed = false;
    private bool $ended = false;
    private int $bytesWritten = 0;
    private ?CancellationToken $token = null;

    public function __construct(
        private ResponseEntity $entity,
        private HttpOutputStream $stream
    ) {
    }

    public function write(string $data): bool {
        if ($this->ended || $this->failed) {
            return false;
        }
        if ($data === '') {
            return $this->isConnected();
        }
        $this->commit();
        if ($this->stream instanceof ChunkedOutputStream) {
            $ok = $this->stream->writeChunk($data);
        } else {
            $ok = $this->stream->write($data) === strlen($data);
            $this->stream->flush();
            $ok = $ok && connection_aborted() === 0;
        }
        if (!$ok) {
            $this->failed = true;
            return false;
        }
        $this->bytesWritten += strlen($data);
        return true;
    }

    public function isConnected(): bool {
        if ($this->ended || $this->failed) {
            return false;
        }
        if ($this->stream instanceof ChunkedOutputStream) {
            return $this->stream->isClientConnected();
        }
        return connection_aborted() === 0;
    }

    public function isCommitted(): bool {
        return $this->committed;
    }

    public function getBytesWritten(): int {
        return $this->bytesWritten;
    }

    public function getCancellationToken(): CancellationToken {
        return $this->token ??= CancellationToken::when(
            fn(): bool => !$this->isConnected(),
            'client disconnected'
        );
    }

    /** True when a write failed because the client went away. */
    public function hasFailed(): bool {
        return $this->failed;
    }

    /**
     * Completes the response (sending the head when nothing was written).
     * Idempotent; later writes return false.
     */
    public function end(): void {
        if ($this->ended) {
            return;
        }
        $this->commit();
        $this->ended = true;
        // @: ending a response whose client is gone only warns.
        @$this->stream->close();
    }

    private function commit(): void {
        if ($this->committed) {
            return;
        }
        $this->committed = true;
        // Chunked transfer: a Content-Length would contradict it.
        $this->entity->getHeaders()->remove('Content-Length');
        AbstractResponseRenderer::writeHead($this->entity, $this->stream);
    }
}
