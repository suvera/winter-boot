<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use Swoole\Atomic;
use Swoole\Table;

class WinterTable {
    private Table $table;
    private Atomic $counter;

    public function __construct(Table $table, Atomic $counter) {
        $this->table = $table;
        $this->counter = $counter;
    }

    public function getTable(): Table {
        return $this->table;
    }

    public function setTable(Table $table): WinterTable {
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