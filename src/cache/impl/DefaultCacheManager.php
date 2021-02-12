<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

class DefaultCacheManager extends SimpleCacheManager {

    public function __construct() {
        $default = new InMemoryCache('default');
        $this->addCache($default);
    }
}