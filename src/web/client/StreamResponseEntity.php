<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\web\http\ResponseEntity;

/**
 * Result of {@see RestTemplate::exchangeStream()}: status and headers of
 * the streamed response. The body itself went to the chunk callback, so
 * getBody() is null on success (or the raw error body when error mapping
 * did not throw).
 */
class StreamResponseEntity extends ResponseEntity {
    private bool $aborted = false;
    private int $bytesReceived = 0;

    /** True when the chunk callback returned false and reading stopped early. */
    public function isAborted(): bool {
        return $this->aborted;
    }

    /** Body bytes handed to the chunk callback (after decoding). */
    public function getBytesReceived(): int {
        return $this->bytesReceived;
    }

    /** @internal set by RestTemplate */
    public function setStreamOutcome(bool $aborted, int $bytesReceived): void {
        $this->aborted = $aborted;
        $this->bytesReceived = $bytesReceived;
    }
}
