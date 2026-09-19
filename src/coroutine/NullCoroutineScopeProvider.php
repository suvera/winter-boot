<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

/**
 * Scope provider used when Swoole is unavailable (CLI, tests, plain FPM).
 * Every caller shares one process-wide scope, matching historic behaviour.
 */
final class NullCoroutineScopeProvider implements CoroutineScopeProvider {

    public function isInCoroutine(): bool {
        return false;
    }

    public function getScopeId(): ?string {
        return null;
    }

    public function defer(callable $fn): void {
        // No coroutine boundary exists; the process owns the delegate.
    }
}
