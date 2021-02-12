<?php
declare(strict_types=1);

namespace dev\winterframework\cache\stereotype;

use Attribute;
use dev\winterframework\cache\aop\CacheEvictAspect;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_METHOD)]
class CachePut implements AopStereoType {
    private WinterAspect $aspect;

    public function __construct(
        public array|string $cacheNames,
        public string $key = '',
        public string $keyGenerator = '',
        public string $cacheManager = '',
        public string $cacheResolver = '',
        public string $condition = '',
        public string $unless = ''
    ) {
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->aspect)) {
            $this->aspect = new CacheEvictAspect();
        }
        return $this->aspect;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
    }

}