<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\CacheCollection;
use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\CacheResolver;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\type\TypeAssert;
use InvalidArgumentException;

abstract class AbstractCacheResolver implements CacheResolver {
    private CacheManager $cacheManager;

    protected function __construct(CacheManager $cacheManager = null) {
        if (!is_null($cacheManager)) {
            $this->cacheManager = $cacheManager;
        }
    }

    public function setCacheManager(CacheManager $cacheManager): void {
        $this->cacheManager = $cacheManager;
    }

    public function getCacheManager(): CacheManager {
        TypeAssert::state(isset($this->cacheManager), "No CacheManager set");
        return $this->cacheManager;
    }

    public function resolveCaches(AopContext $ctx, object $target): CacheCollection {
        $list = $this->getCacheNames($ctx, $target);
        if (count($list) == 0) {
            return CacheCollection::emptyList();
        }

        $list = new CacheCollection();
        foreach ($list as $cacheName) {
            $cache = $this->getCacheManager()->getCache($cacheName);
            if ($cache == null) {
                throw new InvalidArgumentException("Cannot find cache named '"
                    . $cacheName . "' for " . ReflectionUtil::getFqName($ctx->getMethod()));
            }

            $list[] = $cache;
        }
        return $list;
    }

    protected abstract function getCacheNames(AopContext $ctx, object $target): array;
}