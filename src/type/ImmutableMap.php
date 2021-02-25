<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\type;

use ArrayIterator;

class ImmutableMap implements ImmutableCollection {
    /**
     * @var array
     */
    private array $values = [];

    /**
     * ImmutableMap constructor.
     */
    private function __construct() {
    }

    public static function of(array $values): ImmutableMap {
        $obj = new self();
        $obj->values = $values;
        return $obj;
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->values);
    }

    /**
     * @inheritDoc
     */
    public function count(): int {
        return count($this->values);
    }

    public function isEmpty(): bool {
        return empty($this->values);
    }

    public function toArray(): array {
        return $this->values;
    }

    public function contains(mixed $value): bool {
        return in_array($value, $this->values, true);
    }

    public function containsIndex(string|int $index): bool {
        return isset($this->values[$index]);
    }

    public function keys(): array {
        return array_keys($this->values);
    }

    public function values(): array {
        return array_values($this->values);
    }

    public function get(int|string $index): mixed {
        return $this->getOrDefault($index, null);
    }

    public function getOrDefault(int|string $index, mixed $defaultValue): mixed {
        return array_key_exists($index, $this->values) ? $this->values[$index] : $defaultValue;
    }

    public function jsonSerialize(): array {
        return $this->values;
    }

}