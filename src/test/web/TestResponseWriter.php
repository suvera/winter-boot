<?php

declare(strict_types=1);

namespace dev\winterframework\test\web;

use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\http\ResponseWriter;

/**
 * A {@see ResponseWriter} for unit tests of streamed controllers: records
 * every chunk instead of sending it.
 *
 * ```php
 * $writer = new TestResponseWriter();
 * $controller->chat($request)->getBody()->run($writer);
 * $writer->getChunks();          // what the client would have received, chunk by chunk
 * $writer->disconnect();         // simulate the client leaving: writes return false, the token cancels
 * ```
 */
final class TestResponseWriter implements ResponseWriter {
    /** @var string[] */
    private array $chunks = [];
    private bool $connected = true;
    private ?CancellationToken $token = null;

    public function write(string $data): bool {
        if (!$this->connected) {
            return false;
        }
        if ($data !== '') {
            $this->chunks[] = $data;
        }
        return true;
    }

    public function isConnected(): bool {
        return $this->connected;
    }

    public function isCommitted(): bool {
        return $this->chunks !== [];
    }

    public function getBytesWritten(): int {
        return strlen(implode('', $this->chunks));
    }

    public function getCancellationToken(): CancellationToken {
        return $this->token ??= CancellationToken::when(fn(): bool => !$this->connected, 'client disconnected');
    }

    /** The client leaves: later writes return false and the token is cancelled. */
    public function disconnect(): void {
        $this->connected = false;
    }

    /** @return string[] */
    public function getChunks(): array {
        return $this->chunks;
    }

    /** Everything written, as one string. */
    public function getBody(): string {
        return implode('', $this->chunks);
    }
}
