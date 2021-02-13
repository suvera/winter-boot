<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\CacheManager;
use dev\winterframework\stereotype\aop\AopContext;

class NamedCacheResolver extends AbstractCacheResolver {
    private array $cacheNames;

    public function __construct(CacheManager $cacheManager, string...$cacheNames) {
        parent::__construct($cacheManager);
        $this->cacheNames = $cacheNames;
    }

    protected function getCacheNames(AopContext $ctx, object $target): array {
        return $this->cacheNames;
    }

    public function setCacheNames(array $cacheNames): void {
        $this->cacheNames = $cacheNames;
    }

}