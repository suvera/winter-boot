<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

use Swoole\Coroutine;

/**
 * Swoole-backed scope provider. Every live coroutine gets its own scope id
 * (`swoole-cid-<n>`); cleanup hooks are registered with `Coroutine::defer()`
 * so a dead coroutine's delegates can never be inherited by a recycled cid.
 *
 * All Swoole calls are guarded by `class_exists` so merely instantiating
 * this provider without the extension is safe (it then reports "no
 * coroutine", and pools degrade to the process-wide fallback).
 */
final class SwooleCoroutineScopeProvider implements CoroutineScopeProvider {

    public static function isAvailable(): bool {
        return extension_loaded('swoole')
            && class_exists(Coroutine::class)
            && method_exists(Coroutine::class, 'getCid');
    }

    public function isInCoroutine(): bool {
        if (!self::isAvailable()) {
            return false;
        }
        $cid = Coroutine::getCid();
        return $cid !== false && $cid !== -1;
    }

    public function getScopeId(): ?string {
        if (!$this->isInCoroutine()) {
            return null;
        }
        /** @var int $cid */
        $cid = Coroutine::getCid();
        return 'swoole-cid-' . $cid;
    }

    public function defer(callable $fn): void {
        if (!$this->isInCoroutine()) {
            return;
        }
        Coroutine::defer($fn);
    }
}
