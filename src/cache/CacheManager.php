<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

interface CacheManager {

    public function getCache(string $name): ?Cache;

    /**
     * @return string[]
     */
    public function getCacheNames(): array;
}