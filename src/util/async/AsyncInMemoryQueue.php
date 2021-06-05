<?php
declare(strict_types=1);

namespace dev\winterframework\util\async;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Atomic;
use Swoole\Table;

class AsyncInMemoryQueue implements AsyncQueueStore {
    use Wlf4p;

    private Table $table;
    private Atomic $counter;

    public function __construct(
        private ApplicationContext $ctx,
        private int $workerId,
        private int $capacity,
        private int $argSize
    ) {
        $this->counter = new Atomic(1);

        $table = new Table($this->capacity);
        $table->column('timestamp', Table::TYPE_INT);
        $table->column('workerId', Table::TYPE_INT);
        $table->column('className', Table::TYPE_STRING, 128);
        $table->column('methodName', Table::TYPE_STRING, 64);
        $table->column('arguments', Table::TYPE_STRING, $argSize);
        $table->create();

        self::logInfo("Shared Async Table Capacity: $capacity, Memory: " . $table->getMemorySize() . ' bytes');

        $this->table = $table;
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
        return $this->table->count();
    }

}