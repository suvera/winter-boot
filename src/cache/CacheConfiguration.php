<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

class CacheConfiguration {

    public function __construct(
        public int $maximumSize = PHP_INT_MAX - 1,
        public int $expireAfterWriteMs = -1,
        public int $expireAfterAccessMs = -1
    ) {
    }
}