<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use JsonException;

/**
 * One complete WebSocket message (fragments are already joined).
 */
final class WebSocketMessage {

    public function __construct(
        private string $payload,
        private bool $binary = false
    ) {
    }

    public static function text(string $payload): self {
        return new self($payload, false);
    }

    public static function binary(string $payload): self {
        return new self($payload, true);
    }

    public function getPayload(): string {
        return $this->payload;
    }

    public function isText(): bool {
        return !$this->binary;
    }

    public function isBinary(): bool {
        return $this->binary;
    }

    public function getSize(): int {
        return strlen($this->payload);
    }

    /**
     * The payload decoded as JSON (objects as arrays).
     *
     * @throws JsonException when it is not valid JSON
     */
    public function json(): mixed {
        return json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
