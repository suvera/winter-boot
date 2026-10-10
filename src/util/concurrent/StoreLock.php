<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\core\System;
use Closure;

/**
 * One caller's handle on a lock kept in a LockStore. Each handle has its
 * own random owner token, so two handles for the same name (two
 * coroutines, two workers, two pods) always exclude each other, and a
 * handle can only release or extend a lease it really holds.
 */
class StoreLock implements Lock {

    private string $owner;
    private bool $held = false;

    /**
     * @param int $ttlSeconds lease length; 0 = until unlocked
     * @param Closure|null $onChange fn(StoreLock $lock, bool $held), used by the manager to track held locks
     */
    public function __construct(
        private readonly LockStore $store,
        private readonly string $name,
        private int $ttlSeconds = 0,
        private readonly int $pollMs = 50,
        private readonly ?Closure $onChange = null,
    ) {
        $this->owner = bin2hex(random_bytes(16));
    }

    public function tryLock(int $waitForMs = 0): bool {
        if ($this->held) {
            return true;
        }
        $deadline = System::currentTimeMillis() + max(0, $waitForMs);
        while (true) {
            if ($this->store->acquire($this->name, $this->owner, $this->ttlSeconds * 1000)) {
                $this->held = true;
                if ($this->onChange) {
                    ($this->onChange)($this, true);
                }
                return true;
            }
            $left = $deadline - System::currentTimeMillis();
            if ($left <= 0) {
                return false;
            }
            LockWait::sleepMs(min($this->pollMs, $left));
        }
    }

    public function isLocked(): bool {
        return $this->held;
    }

    public function unlock(): void {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        try {
            $this->store->release($this->name, $this->owner);
        } finally {
            if ($this->onChange) {
                ($this->onChange)($this, false);
            }
        }
    }

    /**
     * Extends the lease by $ttl seconds from now (0 = keep the current TTL
     * length). Throws when the lease was lost, e.g. it expired and another
     * owner took it, so long work can stop instead of running unprotected.
     */
    public function update(int $ttl = 0): void {
        if (!$this->held) {
            return;
        }
        if ($ttl > 0) {
            $this->ttlSeconds = $ttl;
        }
        if (!$this->store->refresh($this->name, $this->owner, $this->ttlSeconds * 1000)) {
            $this->held = false;
            if ($this->onChange) {
                ($this->onChange)($this, false);
            }
            throw new LockException('Lock "' . $this->name . '" was lost before it could be extended');
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function isDistributed(): bool {
        return true;
    }

    public function getOwner(): string {
        return $this->owner;
    }
}
