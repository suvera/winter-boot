<?php
declare(strict_types=1);

namespace dev\winterframework\task;

use Swoole\Process;

interface TaskPoolExecutor {

    public function enqueue(string $className, string $methodName, array $args = null);

    public function executeAll(Process $worker, int $workerId);

    public function getPoolSize(): int;

    public function getQueueCapacity(): int;

}