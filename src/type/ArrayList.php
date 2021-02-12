<?php

declare(strict_types=1);

namespace dev\winterframework\type;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

abstract class ArrayList implements Countable, ArrayAccess, IteratorAggregate {
    /**
     * @var mixed[]
     */
    protected array $values = [];

    public function count(): int {
        return count($this->values);
    }

    public function offsetExists($offset): bool {
        return array_key_exists($offset, $this->values);
    }

    public function offsetUnset($offset): void {
        if (isset($this->values[$offset])) {
            unset($this->values[$offset]);
        }
    }

    public function offsetGet($offset): mixed {
        if (isset($this->values[$offset])) {
            return $this->values[$offset];
        }
        return null;
    }

    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->values);
    }

    public function addAll(array $items): void {
        foreach ($items as $value) {
            $this[] = $value;
        }
    }

    public function offsetSet($offset, $value): void {
        $this->values[] = $value;
    }

    public static function ofArray(array $values): mixed {
        $obj = new static();

        foreach ($values as $value) {
            $obj[] = $value;
        }

        return $obj;
    }

}