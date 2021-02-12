<?php
declare(strict_types=1);

namespace dev\winterframework\cache\stereotype;

use Attribute;
use dev\winterframework\cache\aop\CacheableAspect;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_METHOD)]
class Cacheable implements AopStereoType {
    private WinterAspect $aspect;

    public function __construct(
        public array|string $cacheNames = 'default',
        public string $key = '',
        public string $keyGenerator = '',
        public string $cacheManager = '',
        public string $cacheResolver = '',
        public string $condition = '',
        public string $unless = ''
    ) {
    }

    /**
     * @return string[]
     */
    public function getCacheNames(): array {
        if (is_array($this->cacheNames)) {
            $cacheNames = $this->cacheNames;
        } else {
            $cacheNames = [$this->cacheNames];
        }
        return $cacheNames;
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->aspect)) {
            $this->aspect = new CacheableAspect();
        }
        return $this->aspect;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
    }

}