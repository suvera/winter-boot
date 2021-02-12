<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheManager;

abstract class AbstractCacheManager implements CacheManager {
    
    public abstract function setCaches(array $caches): void;

    public abstract function addCache(Cache ...$caches): void;
}