<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

interface LockManager {

    public function provideLock(
        string $name,
        int $ttl = 0
    ): Lock;

    public function removeLock(Lock|string $lock): bool;

    public function updateLock(Lock|string $lock, int $ttl = 0): bool;

    public function unLock(string|Lock $lock): bool;

    /**
     * Get all locks
     *
     * @return Locks
     */
    public function getLocks(): Locks;
}