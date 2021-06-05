<?php
declare(strict_types=1);

namespace dev\winterframework\task;

interface TaskPoolExecutor {

    public function enqueue(string $className, string $methodName, array $args = null);

    public function getPoolSize(): int;

    public function getQueueCapacity(): int;

}