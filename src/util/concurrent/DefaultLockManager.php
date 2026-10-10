<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\util\log\Wlf4p;
use Throwable;

/**
 * Host-local locking (flock). Every provideLock() call returns a new
 * handle: a handle shared between callers would let a second coroutine
 * "acquire" a lock the first one already holds. getLocks() lists only the
 * locks this process holds, so the registry doesn't grow with lock names.
 */
class DefaultLockManager implements LockManager {
    use Wlf4p;

    /** @var array<string, Lock> held locks by name */
    private array $held = [];

    public function getLocks(): Locks {
        $locks = new Locks();
        foreach ($this->held as $name => $lock) {
            $locks[$name] = $lock;
        }
        return $locks;
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function createLock(string $name, int $ttl = 0): Lock {
        return new LocalLock($name);
    }

    public function provideLock(string $name, int $ttl = 0): Lock {
        return new TrackedLock($this->createLock($name, $ttl), function (Lock $lock, bool $held) {
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
        $lock = is_string($lock) ? ($this->held[$lock] ?? null) : $lock;
        if ($lock === null) {
            return false;
        }
        $lock->update($ttl);
        return true;
    }

    public function unLock(string|Lock $lock): bool {
        $lock = is_string($lock) ? ($this->held[$lock] ?? null) : $lock;
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
}
