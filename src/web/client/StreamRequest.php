<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\util\concurrent\CancellationToken;

/**
 * One streamed request, as handed to a {@see StreamingRestClientTransport}:
 * everything RestTemplate prepared (expanded URL, merged headers, encoded
 * body) plus the timeouts and the cancellation token. New options are
 * added here, so transports never see their signature change.
 */
final class StreamRequest {

    /**
     * @param array<string, string> $headers flat request headers, already merged
     * @param float $idleTimeout longest wait for the next bytes (seconds), not a total budget
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $idleTimeout,
        public readonly float $connectTimeout,
        public readonly ?CancellationToken $cancel = null
    ) {
    }

    public function isCancelled(): bool {
        return $this->cancel !== null && $this->cancel->isCancelled();
    }
}
