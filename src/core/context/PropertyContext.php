<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

interface PropertyContext {

    public function get(string $name, mixed $default = null): mixed;

    public function set(string $name, mixed $value): mixed;

    public function getStr(string $name, string $default = null): string;

    public function getBool(string $name, bool $default = null): bool;

    public function getInt(string $name, int $default = null): int;

    public function getFloat(string $name, float $default = null): float;

    public function getAll(): array;

    public function has(string $name): bool;

}