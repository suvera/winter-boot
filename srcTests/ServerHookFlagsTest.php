<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\app\WinterWebSwooleApplication;
use dev\winterframework\exception\WinterException;
use winterBootTests\Support\TestCase;

/**
 * server.swoole.hook_flags: numbers, SWOOLE_HOOK_* names, OR'ed lists and
 * "-NAME" removals resolve to the integer Swoole expects.
 */
final class ServerHookFlagsTest extends TestCase {

    private function skipWithoutSwoole(): bool {
        return !defined('SWOOLE_HOOK_ALL');
    }

    public function testNumberPassesThrough(): void {
        $this->assertSame(0, WinterWebSwooleApplication::resolveHookFlags(0));
        $this->assertSame(65536, WinterWebSwooleApplication::resolveHookFlags(65536));
        $this->assertSame(65536, WinterWebSwooleApplication::resolveHookFlags('65536'));
    }

    public function testConstantNames(): void {
        if ($this->skipWithoutSwoole()) {
            return;
        }
        $this->assertSame(SWOOLE_HOOK_ALL, WinterWebSwooleApplication::resolveHookFlags('SWOOLE_HOOK_ALL'));
        $this->assertSame(SWOOLE_HOOK_ALL, WinterWebSwooleApplication::resolveHookFlags('all'));
        $this->assertSame(
            SWOOLE_HOOK_PDO_PGSQL | SWOOLE_HOOK_TCP,
            WinterWebSwooleApplication::resolveHookFlags(['SWOOLE_HOOK_PDO_PGSQL', 'TCP'])
        );
    }

    public function testRemovalEntries(): void {
        if ($this->skipWithoutSwoole()) {
            return;
        }
        $this->assertSame(
            SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_NATIVE_CURL,
            WinterWebSwooleApplication::resolveHookFlags(['SWOOLE_HOOK_ALL', '-SWOOLE_HOOK_CURL', '-NATIVE_CURL'])
        );
        $this->assertSame(0, WinterWebSwooleApplication::resolveHookFlags([]));
    }

    public function testUnknownNameFailsStartup(): void {
        $this->assertThrows(
            WinterException::class,
            fn() => WinterWebSwooleApplication::resolveHookFlags(['SWOOLE_HOOK_PDO_PGSQL', 'SWOOLE_HOOK_NOPE'])
        );
        $this->assertThrows(
            WinterException::class,
            fn() => WinterWebSwooleApplication::resolveHookFlags('PHP_VERSION')
        );
    }
}
