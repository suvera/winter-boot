<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\core\System;
use dev\winterframework\util\log\Wlf4p;
use SplFileInfo;
use SplFileObject;
use Throwable;

class LocalLock implements Lock {
    use Wlf4p;

    private SplFileInfo $fileInfo;
    private ?SplFileObject $fileObj = null;

    public function __construct(
        private string $name
    ) {
        $this->fileInfo = new SplFileInfo(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . hash('sha256', $name) . '.lock'
        );
    }

    public function tryLock(int $waitForMs = 0): bool {
        if ($this->isLocked()) {
            return true;
        }

        if ($waitForMs < 0) {
            $waitForMs = 0;
        }
        $waitUntil = System::currentTimeMillis() + $waitForMs;
        while (true) {
            $locked = $this->doLock();
            if ($locked) {
                return true;
            }
            if (System::currentTimeMillis() >= $waitUntil) {
                break;
            }
            // Yields only this coroutine under Swoole instead of blocking the worker.
            LockWait::sleepMs(5);
        }
        return false;
    }

    /**
     * flock() on a handle this instance owns. Locks belong to the open file
     * description, so a second LocalLock (another coroutine in the same
     * worker, or another process) cannot acquire it, and the kernel drops
     * the lock when the holder dies, so there is no stale-PID cleanup race.
     */
    private function doLock(): bool {
        try {
            $fileObj = $this->fileInfo->openFile('c+');
        } catch (Throwable $e) {
            self::logException($e);
            return false;
        }

        if (!$fileObj->flock(LOCK_EX | LOCK_NB)) {
            return false;
        }
        $fileObj->ftruncate(0);
        $fileObj->fwrite(getmypid() . ':' . time());
        $fileObj->fflush();
        $this->fileObj = $fileObj;
        return true;
    }

    public function isLocked(): bool {
        return $this->fileObj !== null;
    }

    public function unlock(): void {
        if ($this->fileObj) {
            $this->fileObj->ftruncate(0);
            $this->fileObj->flock(LOCK_UN);
            // The file stays: unlinking it would let a waiter lock a
            // different inode than the next opener.
            $this->fileObj = null;
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function isDistributed(): bool {
        return false;
    }

    public function update(int $ttl = 0): void {
    }

}