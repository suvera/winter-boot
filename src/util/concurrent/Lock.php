<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

interface Lock {
    public function __construct(string $name);

    public function tryLock(): bool;

    public function isLocked(): bool;

    public function unlock(): void;

    public function getName(): string;

    public function isDistributed(): bool;
}