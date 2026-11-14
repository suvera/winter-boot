<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\web\http\ResponseEntity;

/**
 * Result of {@see RestTemplate::exchangeStream()}: the status and headers
 * of the streamed response and how the stream ended. The body itself went
 * to the item callback, so getBody() is null on success (or the raw error
 * body when error mapping did not throw).
 */
class StreamExchangeResult extends ResponseEntity {
    private bool $aborted = false;
    private bool $cancelled = false;
    private bool $completed = false;
    private int $bytesReceived = 0;
    private int $itemsDelivered = 0;

    /** The item callback returned false and reading stopped early. */
    public function isAborted(): bool {
        return $this->aborted;
    }

    /** The cancellation token was cancelled before the stream ended. */
    public function isCancelled(): bool {
        return $this->cancelled;
    }

    /**
     * The stream ended normally: the server closed it, or the decoder saw
     * its end marker (`[DONE]`).
     */
    public function isCompleted(): bool {
        return $this->completed;
    }

    /** Body bytes received (before decoding). */
    public function getBytesReceived(): int {
        return $this->bytesReceived;
    }

    /** Items handed to the callback (chunks, events or lines). */
    public function getItemsDelivered(): int {
        return $this->itemsDelivered;
    }

    /** @internal set by RestTemplate */
    public function setStreamOutcome(bool $aborted, bool $cancelled, bool $completed, int $bytesReceived, int $itemsDelivered): void {
        $this->aborted = $aborted;
        $this->cancelled = $cancelled;
        $this->completed = $completed;
        $this->bytesReceived = $bytesReceived;
        $this->itemsDelivered = $itemsDelivered;
    }
}
