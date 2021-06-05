<?php
declare(strict_types=1);

namespace dev\winterframework\util\async;

use dev\winterframework\core\context\ApplicationContext;

interface AsyncQueueStore {

    public function __construct(
        ApplicationContext $ctx,
        int $workerId,
        int $capacity,
        int $argSize
    );

    public function enqueue(AsyncQueueRecord $record): int;

    public function dequeue(): ?AsyncQueueRecord;

    public function size(): int;

    /**
     * @return AsyncQueueRecord[]
     */
    public function getAll(int $limit = PHP_INT_MAX): array;

    public function deleteAll(): void;

}