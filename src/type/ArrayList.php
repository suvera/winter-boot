<?php
declare(strict_types=1);

namespace dev\winterframework\type;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use OutOfBoundsException;

abstract class ArrayList implements Countable, ArrayAccess, IteratorAggregate {

    protected static mixed $EMPTY_LIST;
    protected bool $emptyList = false;

    /**
     * @var mixed[]
     */
    protected array $values = [];

    public function count(): int {
        return count($this->values);
    }

    public function clear(): void {
        $this->values = [];
    }

    public function getArray(): array {
        return $this->values;
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
        if ($this->emptyList) {
            throw new OutOfBoundsException('Could not add item to "EmptyList" object');
        }
        if (is_null($offset)) {
            $this->values[] = $value;
        } else {
            $this->values[$offset] = $value;
        }
    }

    public static function ofValues(mixed ...$values): static {
        return static::ofArray($values);
    }

    public static function ofArray(array $values): static {
        $obj = new static();

        foreach ($values as $value) {
            $obj[] = $value;
        }

        return $obj;
    }

    public final static function emptyList(): static {
        if (isset(static::$EMPTY_LIST)) {
            return static::$EMPTY_LIST;
        }

        static::$EMPTY_LIST = new static();
        static::$EMPTY_LIST->emptyList = true;
        return static::$EMPTY_LIST;
    }

}