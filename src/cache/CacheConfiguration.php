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

    public static function get(
        int $maximumSize = PHP_INT_MAX - 1,
        int $expireAfterWriteMs = -1,
        int $expireAfterAccessMs = -1
    ): self {
        return new self($maximumSize, $expireAfterWriteMs, $expireAfterAccessMs);
    }
}