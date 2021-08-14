<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

interface KvTemplate {
    public function get(string $domain, string $key): mixed;

    public function put(string $domain, string $key, mixed $data, int $ttl = 0): bool;

    public function putIfNot(string $domain, string $key, mixed $data, int $ttl = 0): bool;

    public function del(string $domain, string $key): bool;

    public function has(string $domain, string $key): bool;

    public function ping(): int;

    public function delAll(string $domain): bool;

    public function incr(string $domain, string $key, int|float $incVal = null): int|float;

    public function decr(string $domain, string $key, int|float $decVal = null): int|float;

    public function append(string $domain, string $key, string $append): int;

    public function getSet(string $domain, string $key, mixed $value): mixed;

    public function getSetIfNot(string $domain, string $key, mixed $data, int $ttl = 0): mixed;

    public function strLen(string $domain, string $key): int;

    public function keys(string $domain, string $key): array;

    public function getAll(string $domain): array;

    public function stats(): array;
}