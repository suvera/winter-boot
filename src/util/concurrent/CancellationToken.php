<?php

declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

/**
 * A cancellation flag shared between a long-running task and whoever may
 * stop it. The task checks {@see isCancelled()} between steps (or passes
 * the token on, e.g. to RestTemplate::exchangeStream(), which then stops
 * its HTTP request promptly); anyone holding the token calls cancel().
 *
 * WebSocket sessions hand them out per task with
 * WebSocketSession::beginWork(); a "stop" message, a newer task of the same
 * name or the connection closing cancels it:
 *
 * ```php
 * $token = $session->beginWork('answer');
 * try {
 *     $rest->exchangeStream('POST', $url, $entity, fn (SseEvent $e) => $session->send($e->data),
 *         decoder: StreamDecoder::sse(), cancel: $token);
 * } finally {
 *     $session->endWork('answer', $token);
 * }
 * ```
 *
 * Plain state, no I/O: safe to share between coroutines of one worker.
 */
final class CancellationToken {
    private bool $cancelled = false;
    private string $reason = '';
    /** @var (\Closure(): bool)|null */
    private ?\Closure $condition = null;
    private string $conditionReason = '';

    /**
     * A token that also counts as cancelled as soon as $condition returns
     * true (checked on every isCancelled()), e.g. "the client disconnected".
     * Nobody has to call cancel(): whatever polls the token notices.
     *
     * @param callable(): bool $condition cheap, side-effect free check
     */
    public static function when(callable $condition, string $reason = 'cancelled'): self {
        $token = new self();
        $token->condition = $condition(...);
        $token->conditionReason = $reason;
        return $token;
    }

    public function cancel(string $reason = 'cancelled'): void {
        if (!$this->cancelled) {
            $this->cancelled = true;
            $this->reason = $reason;
            $this->condition = null;
        }
    }

    public function isCancelled(): bool {
        if (!$this->cancelled && $this->condition !== null && ($this->condition)()) {
            $this->cancel($this->conditionReason);
        }
        return $this->cancelled;
    }

    public function getReason(): string {
        return $this->isCancelled() ? $this->reason : '';
    }

    /** @throws CancelledException when cancelled */
    public function throwIfCancelled(): void {
        if ($this->isCancelled()) {
            throw new CancelledException($this->reason);
        }
    }
}
