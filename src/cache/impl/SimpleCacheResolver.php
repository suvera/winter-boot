<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\stereotype\aop\AopContext;

class SimpleCacheResolver extends AbstractCacheResolver {

    public function __construct(CacheManager $cacheManager) {
        parent::__construct($cacheManager);
    }

    protected function getCacheNames(AopContext $ctx, object $target): array {
        /** @var Cacheable $stereo */
        $stereo = $ctx->getStereoType();

        return $stereo->getCacheNames();
    }

}