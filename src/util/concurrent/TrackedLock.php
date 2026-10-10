<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use Closure;

/**
 * Wraps a Lock and reports when it is acquired or released, so a manager
 * can list the locks it holds without caching one Lock per name.
 */
final class TrackedLock implements Lock {

    public function __construct(
        private readonly Lock $inner,
        private readonly Closure $onChange,
    ) {
    }

    public function tryLock(int $waitForMs = 0): bool {
        $was = $this->inner->isLocked();
        $ok = $this->inner->tryLock($waitForMs);
        if ($ok && !$was) {
            ($this->onChange)($this, true);
        }
        return $ok;
    }

    public function isLocked(): bool {
        return $this->inner->isLocked();
    }

    public function unlock(): void {
        $was = $this->inner->isLocked();
        try {
            $this->inner->unlock();
        } finally {
            if ($was) {
                ($this->onChange)($this, false);
            }
        }
    }

    public function update(int $ttl = 0): void {
        $this->inner->update($ttl);
    }

    public function getName(): string {
        return $this->inner->getName();
    }

    public function isDistributed(): bool {
        return $this->inner->isDistributed();
    }

    public function getInner(): Lock {
        return $this->inner;
    }
}
