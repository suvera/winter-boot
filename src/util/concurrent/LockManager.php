<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

interface LockManager {

    public function createLock(
        string $name,
        string $provider
    ): Lock;

    /**
     * Get all locks
     *
     * @return Lock[]
     */
    public function getLocks(): array;
}