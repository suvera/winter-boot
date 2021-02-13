<?php
declare(strict_types=1);

namespace test\winterframework\cache\classes;

use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\cache\stereotype\CacheEvict;
use dev\winterframework\cache\stereotype\CachePut;
use dev\winterframework\stereotype\Service;

#[Service]
class Cache001 {
    private int $val = 10;
    private int $val22 = 10;

    public function noCacheTest(): int {
        $this->val++;
        return $this->val;
    }

    public function reset(): void {
        $this->val22 = 10;
    }

    #[Cacheable(key: "testKey")]
    public function cachedTest(): int {
        $this->val22++;
        return $this->val22;
    }

    #[CachePut(key: "testKey")]
    public function cachePutTest(): int {
        $this->val22++;
        return $this->val22;
    }

    #[CacheEvict(key: "testKey")]
    public function cacheEvictTest(): void {
    }

    #[CacheEvict(allEntries: true)]
    public function cacheEvictAllTest(): void {
    }
}