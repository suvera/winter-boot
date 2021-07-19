<?php
declare(strict_types=1);

namespace dev\winterframework\util\async;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\shm\ShmTable;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Atomic;

class AsyncInMemoryQueue implements AsyncQueueStore {
    use Wlf4p;

    private ShmTable $table;
    private Atomic $counter;

    public function __construct(
        private ApplicationContext $ctx,
        private int $workerId,
        private int $capacity,
        private int $argSize
    ) {
        $this->counter = new Atomic(1);

        $this->table = new ShmTable(
            $this->capacity,
            [
                ['timestamp', ShmTable::TYPE_INT],
                ['workerId', ShmTable::TYPE_INT],
                ['className', ShmTable::TYPE_STRING, 128],
                ['methodName', ShmTable::TYPE_STRING, 64],
                ['arguments', ShmTable::TYPE_STRING, $argSize]
            ]
        );
        self::logInfo("Shared Async Table Capacity: $capacity, Memory: " . $this->table->getMemorySize() . ' bytes');
    }

    public function enqueue(AsyncQueueRecord $record): int {
        if ($record->getId() == 0) {
            $record->setId($this->counter->add(1));
        }

        $this->table[$record->getId()] = $record->toArray();
        return $record->getId();
    }

    public function dequeue(): ?AsyncQueueRecord {
        foreach ($this->table as $id => $row) {
            $this->delete($id);
            return AsyncQueueRecord::fromArray(intval($id), $row);
        }

        return null;
    }

    protected function delete(int|string $id): bool {
        unset($this->table[intval($id)]);
        return true;
    }

    /**
     * @param int $limit
     * @return AsyncQueueRecord[]
     */
    public function getAll(int $limit = PHP_INT_MAX): array {
        $data = [];
        foreach ($this->table as $id => $row) {

            $data[] = AsyncQueueRecord::fromArray(intval($id), $row);
            $limit--;
            if ($limit == 0) {
                break;
            }
        }

        return $data;
    }

    public function deleteAll(): void {
        foreach ($this->table as $id => $row) {
            $this->table->delete($id);
        }
    }

    public function size(): int {
        return $this->table->getRowCount();
    }

}