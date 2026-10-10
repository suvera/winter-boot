<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

/**
 * Sleeping between lock attempts without blocking a Swoole worker: inside
 * a coroutine only the waiting coroutine yields; elsewhere usleep() is fine.
 */
final class LockWait {

    public static function sleepMs(int $ms): void {
        if ($ms <= 0) {
            return;
        }
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($ms / 1000);
            return;
        }
        usleep($ms * 1000);
    }
}
