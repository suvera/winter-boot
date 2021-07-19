<?php
declare(strict_types=1);

namespace dev\winterframework\io\shm;

use ArrayAccess;
use Countable;
use Iterator;
use Swoole\Table;

class ShmTable implements ArrayAccess, Iterator, Countable {

    public const TYPE_INT = Table::TYPE_INT;
    public const TYPE_STRING = Table::TYPE_STRING;
    public const TYPE_FLOAT = Table::TYPE_FLOAT;

    protected Table $intern;

    public function __construct(
        protected int $size,
        protected array $columns
    ) {
        $this->intern = new Table($this->size);
        foreach ($this->columns as $column) {
            $this->intern->column($column[0], $column[1], $column[2] ?? 0);
        }
        $this->intern->create();
    }

    public function get(string|int|float $key): ?array {
        return $this->intern->get('' . $key);
    }

    public function exists(string|int|float $key): bool {
        return $this->intern->exists('' . $key);
    }

    public function delete(string|int|float $key): bool {
        return $this->intern->del('' . $key);
    }

    public function put(string|int|float $key, array $row): bool {
        return $this->intern->set('' . $key, $row);
    }

    public function putIfNot(string|int|float $key, array $row): bool {
        if ($this->exists($key)) {
            return false;
        }
        return $this->put($key, $row);
    }

    public function getAll(): array {
        $rows = [];
        foreach ($this->intern as $id => $row) {
            $rows[$id] = $row;
        }
        return $rows;
    }

    public function getMemorySize(): int {
        return $this->intern->getMemorySize();
    }

    public function getRowCount(): int {
        return $this->intern->count();
    }

    public function destroy(): void {
        $this->intern->destroy();
    }

    public function offsetExists($offset): bool {
        return $this->exists($offset);
    }

    public function offsetGet($offset): ?array {
        return $this->get($offset);
    }

    public function offsetSet($offset, $value): void {
        $this->put($offset, $value);
    }

    public function offsetUnset($offset) {
        $this->delete($offset);
    }

    public function current(): ?array {
        return $this->intern->current();
    }

    public function next() {
        $this->intern->next();
    }

    public function key(): string {
        return '' . $this->intern->key();
    }

    public function valid() {
        return $this->intern->valid();
    }

    public function rewind() {
        $this->intern->rewind();
    }

    public function count(): int {
        return $this->intern->count();
    }

}