<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\concurrent;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\util\NamedComponent;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\concurrent\LockableAspect;
use dev\winterframework\util\concurrent\LockManager;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class Lockable implements AopStereoType {
    use StereoTypeValidations;
    use NamedComponent;

    private WinterAspect $aspect;

    public function __construct(
        public string $name,
        public int $ttlSeconds = 0,
        public int $waitMilliSecs = 0,
        public string $lockManager = ''
    ) {
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        if (!empty($this->lockManager)
            && !is_a($this->lockManager, LockManager::class, true)) {
            throw new TypeError("LockManager provided for #[Lockable] attribute must implement "
                . LockManager::class . ' at the method '
                . ReflectionUtil::getFqName($ref));
        }

        $this->validateAopMethod($ref, 'Lockable');

        $this->initNameObject($this->name);
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->aspect)) {
            $this->aspect = new LockableAspect();
        }
        return $this->aspect;
    }


}