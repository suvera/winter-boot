<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

use dev\winterframework\exception\IllegalStateException;

interface Cache {
    public function clear(): void;

    public function evict(string $key): bool;

    public function has(string $key): bool;

    public function get(string $key): ValueWrapper;

    /**
     * @param string $key
     * @param callable $valueProvider
     * @return ValueWrapper
     * @throws ValueRetrievalException
     */
    public function getOrProvide(string $key, callable $valueProvider): ValueWrapper;

    /**
     * @param string $key
     * @param string $class
     * @return object|null
     * @throws IllegalStateException
     */
    public function getAsType(string $key, string $class): ?object;

    public function getName(): string;

    public function getNativeCache(): object;

    public function invalidate(): bool;

    public function put(string $key, mixed $value): void;

    public function putIfAbsent(string $key, mixed $value): ValueWrapper;
}