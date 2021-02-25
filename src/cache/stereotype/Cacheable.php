<?php
declare(strict_types=1);

namespace dev\winterframework\cache\stereotype;

use Attribute;
use dev\winterframework\cache\aop\CacheableAspect;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\util\NamedComponent;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class Cacheable implements AopStereoType {
    use StereoTypeValidations;
    use NamedComponent;
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
        if (!is_array($this->cacheNames)) {
            $this->cacheNames = [$this->cacheNames];
        }
        TypeAssert::stringArray($this->cacheNames);
    }

    /**
     * @return string[]
     */
    public function getCacheNames(): array {
        return $this->cacheNames;
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

        $this->validateAopMethod($ref, 'Cacheable');

        $cacheable = $ref->getAttributes(CachePut::class);
        if (!empty($cacheable)) {
            throw new TypeError("Method cannot be annotated with #[Cacheable] "
                . "and #[CachePut] at same time for method "
                . ReflectionUtil::getFqName($ref));
        }
        $this->initNameObject($this->key);
    }

}