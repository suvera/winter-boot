<?php
declare(strict_types=1);

namespace dev\winterframework\type;

use Countable;
use IteratorAggregate;
use JsonSerializable;

interface ImmutableCollection extends Countable, IteratorAggregate, JsonSerializable {
    public function toArray(): array;

    public function isEmpty(): bool;

    public function contains(mixed $value): bool;

    public function containsIndex(string|int $index): bool;

    public function get(string|int $index): mixed;

    public function getOrDefault(string|int $index, mixed $defaultValue): mixed;
}