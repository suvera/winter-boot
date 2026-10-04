<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * Headers plus optional body of one outgoing request, mirroring Spring's
 * HttpEntity. Pass it to {@see RestTemplate::exchange()} when a call needs
 * both custom headers and a body; for header-only calls use
 * {@see HttpEntity::withHeaders()}.
 */
final class HttpEntity {
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private mixed $body = null,
        private array $headers = []
    ) {
    }

    /** @param array<string, string> $headers */
    public static function withHeaders(array $headers): self {
        return new self(null, $headers);
    }

    /** @param array<string, string> $headers */
    public static function withJsonBody(mixed $body, array $headers = []): self {
        return new self($body, $headers);
    }

    public static function empty(): self {
        return new self();
    }

    public function getBody(): mixed {
        return $this->body;
    }

    /** @return array<string, string> */
    public function getHeaders(): array {
        return $this->headers;
    }

    public function hasBody(): bool {
        return $this->body !== null;
    }
}
