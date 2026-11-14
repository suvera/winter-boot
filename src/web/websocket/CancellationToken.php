<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

/**
 * A flag a long-running task checks to stop early. Obtain one per task with
 * {@see WebSocketSession::beginWork()}; another message ("stop"), a newer
 * task of the same name, or the connection closing cancels it.
 *
 * ```php
 * $token = $session->beginWork('answer');
 * try {
 *     $rest->exchangeStream('POST', $url, $entity, function (string $bytes) use ($session, $token) {
 *         $session->send(['type' => 'chunk', 'text' => $bytes]);
 *         return !$token->isCancelled();      // false aborts the upstream stream
 *     });
 * } finally {
 *     $session->endWork('answer', $token);
 * }
 * ```
 */
final class CancellationToken {
    private bool $cancelled = false;
    private string $reason = '';

    public function cancel(string $reason = 'cancelled'): void {
        if (!$this->cancelled) {
            $this->cancelled = true;
            $this->reason = $reason;
        }
    }

    public function isCancelled(): bool {
        return $this->cancelled;
    }

    public function getReason(): string {
        return $this->reason;
    }

    /** @throws CancelledException when cancelled */
    public function throwIfCancelled(): void {
        if ($this->cancelled) {
            throw new CancelledException($this->reason);
        }
    }
}
