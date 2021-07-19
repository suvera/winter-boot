<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\io\shm\ShmTable;
use Swoole\Atomic;

class WinterTable {
    private ShmTable $table;
    private Atomic $counter;

    public function __construct(ShmTable $table, Atomic $counter) {
        $this->table = $table;
        $this->counter = $counter;
    }

    public function getTable(): ShmTable {
        return $this->table;
    }

    public function setTable(ShmTable $table): WinterTable {
        $this->table = $table;
        return $this;
    }

    public function insert(array $row): int {
        $id = $this->counter->add(1);
        $this->table[$id] = $row;
        return $id;
    }

    public function update(int $id, array $row): void {
        $this->table[$id] = $row;
    }

    public function delete(int $id): void {
        unset($this->table[$id]);
    }

    public function getCounter(): Atomic {
        return $this->counter;
    }

    public function setCounter(Atomic $counter): WinterTable {
        $this->counter = $counter;
        return $this;
    }

}