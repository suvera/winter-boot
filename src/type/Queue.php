<?php
declare(strict_types=1);

namespace dev\winterframework\type;

interface Queue {

    public function add(mixed $item, int $timeoutMs = 0): bool;

    public function poll(int $timeoutMs = 0): mixed;

    public function isUnbounded(): bool;

    public function isCountable(): bool;

    public function size(): int;

}