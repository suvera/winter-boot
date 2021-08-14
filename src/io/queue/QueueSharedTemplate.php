<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

interface QueueSharedTemplate {
    public function dequeue(string $queue): mixed;

    public function enqueue(string $queue, mixed $data): bool;

    public function delete(string $queue): bool;

    public function size(string $queue): int;

    public function ping(): int;

    public function stats(): array;
}