<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

/**
 * Picks the right scope provider for the current runtime: Swoole-backed
 * when the extension is loaded, process-wide fallback otherwise.
 */
final class CoroutineScopeProviders {

    private static ?CoroutineScopeProvider $instance = null;

    public static function create(): CoroutineScopeProvider {
        if (SwooleCoroutineScopeProvider::isAvailable()) {
            return new SwooleCoroutineScopeProvider();
        }
        return new NullCoroutineScopeProvider();
    }

    /**
     * Shared default provider. Prefer injecting a provider in library code;
     * this accessor exists for wiring points that cannot take one.
     */
    public static function shared(): CoroutineScopeProvider {
        if (self::$instance === null) {
            self::$instance = self::create();
        }
        return self::$instance;
    }

    public static function resetShared(): void {
        self::$instance = null;
    }
}
