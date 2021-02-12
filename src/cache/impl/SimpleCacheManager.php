<?php

declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\Cache;
use dev\winterframework\type\TypeAssert;

class SimpleCacheManager extends AbstractCacheManager {
    /**
     * @var Cache[]
     */
    protected array $caches = [];

    public function setCaches(array $caches): void {
        foreach ($caches as $cache) {
            TypeAssert::typeOf($cache, Cache::class);
        }
        $this->caches = $caches;
    }

    public function addCache(Cache ...$caches): void {
        foreach ($caches as $cache) {
            $this->caches[$cache->getName()] = $cache;
        }
    }

    public function getCache(string $name): ?Cache {
        return $this->caches[$name] ?? null;
    }

    public function getCacheNames(): array {
        return array_keys($this->caches);
    }

}