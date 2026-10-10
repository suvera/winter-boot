<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\util\log\Wlf4p;
use Throwable;

/**
 * LockManager over a LockStore. provideLock() returns a new handle for
 * every call; getLocks() lists only the locks this process currently
 * holds, so the registry never grows with the number of lock names used.
 */
class StoreLockManager implements LockManager {
    use Wlf4p;

    /** @var array<string, StoreLock> held locks by name */
    private array $held = [];

    public function __construct(
        private readonly LockStore $store,
        private readonly int $pollMs = 50,
    ) {
    }

    public function getStore(): LockStore {
        return $this->store;
    }

    public function provideLock(string $name, int $ttl = 0): Lock {
        return new StoreLock($this->store, $name, max(0, $ttl), $this->pollMs, function (StoreLock $lock, bool $held) {
            if ($held) {
                $this->held[$lock->getName()] = $lock;
            } elseif (($this->held[$lock->getName()] ?? null) === $lock) {
                unset($this->held[$lock->getName()]);
            }
        });
    }

    public function removeLock(string|Lock $lock): bool {
        return $this->unLock($lock);
    }

    public function updateLock(string|Lock $lock, int $ttl = 0): bool {
        $lock = $this->resolve($lock);
        if ($lock === null) {
            return false;
        }
        try {
            $lock->update($ttl);
            return true;
        } catch (LockException) {
            return false;
        }
    }

    public function unLock(string|Lock $lock): bool {
        $lock = $this->resolve($lock);
        if ($lock === null || !$lock->isLocked()) {
            return false;
        }
        try {
            $lock->unlock();
        } catch (Throwable $e) {
            self::logException($e, 'Could not release lock "' . $lock->getName() . '". ');
            return false;
        }
        return true;
    }

    public function getLocks(): Locks {
        $locks = new Locks();
        foreach ($this->held as $name => $lock) {
            $locks[$name] = $lock;
        }
        return $locks;
    }

    private function resolve(string|Lock $lock): ?Lock {
        return is_string($lock) ? ($this->held[$lock] ?? null) : $lock;
    }
}
