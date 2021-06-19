<?php
declare(strict_types=1);

namespace dev\winterframework\type;

interface Queue {

    public function add(mixed $item, int $timeoutMs = 0): bool;

    public function poll(int $timeoutMs = 0): mixed;

}