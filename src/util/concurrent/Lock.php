<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

interface Lock {
    public function tryLock(int $waitForMs = 0): bool;

    public function isLocked(): bool;

    public function unlock(): void;

    public function update(int $ttl = 0): void;

    public function getName(): string;

    public function isDistributed(): bool;
}