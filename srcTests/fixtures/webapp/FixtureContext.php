<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use Swoole\Coroutine;

/**
 * Stand-in for an application's request-scoped context (SocMon's
 * TenantContext): lives in the current coroutine's context only.
 */
final class FixtureContext {
    private const KEY = 'fixture.user';

    public static function set(?string $user): void {
        Coroutine::getContext()[self::KEY] = $user;
    }

    public static function user(): ?string {
        $ctx = Coroutine::getContext();
        return $ctx[self::KEY] ?? null;
    }

    public static function clear(): void {
        unset(Coroutine::getContext()[self::KEY]);
    }
}
