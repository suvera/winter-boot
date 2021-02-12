<?php
declare(strict_types=1);

namespace test\winterframework\cache\classes;

use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\stereotype\Service;

#[Service]
class Cache001 {
    private int $val = 10;
    private int $val22 = 10;

    public function noCacheTest(): int {
        $this->val++;
        return $this->val;
    }

    #[Cacheable]
    public function cachedTest(): int {
        $this->val22++;
        return $this->val22;
    }
}