<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\util\log\Wlf4p;
use Throwable;

class DefaultLockManager implements LockManager {
    use Wlf4p;

    private Locks $allLocks;

    public function __construct() {
        $this->allLocks = new Locks();
    }

    public function getLocks(): Locks {
        return $this->allLocks;
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function createLock(string $name, int $ttl = 0): Lock {
        return new LocalLock($name);
    }

    public function provideLock(string $name, int $ttl = 0): Lock {
        if (!isset($this->allLocks[$name])) {
            $lock = $this->createLock($name, $ttl);
            $this->allLocks[$name] = $lock;
        }

        return $this->allLocks[$name];
    }

    public function removeLock(string|Lock $lock): bool {
        if (!is_string($lock)) {
            $name = $lock->getName();
        } else {
            $name = $lock;
        }

        if (isset($this->allLocks[$name])) {
            try {
                $this->allLocks[$name]->unlock();
            } catch (Throwable $e) {
                self::logException($e);
            }
            unset($this->allLocks[$name]);
            return true;
        }
        return false;
    }

    public function updateLock(string|Lock $lock, int $ttl = 0): bool {
        if ($lock instanceof Lock) {
            $lock->update($ttl);
            return true;
        }
        if (isset($this->allLocks[$lock])) {
            $this->allLocks[$lock]->update($ttl);
            return true;
        }

        return false;
    }

    public function unLock(string|Lock $lock): bool {
        if ($lock instanceof Lock) {
            $lock->unlock();
            return true;
        }
        if (isset($this->allLocks[$lock])) {
            $this->allLocks[$lock]->unlock();
            return true;
        }

        return false;
    }


}