<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

use dev\winterframework\stereotype\aop\AopContext;

interface CacheResolver {

    public function resolveCaches(AopContext $ctx, object $target): CacheCollection;

    public function getCacheManager(): CacheManager;
}