<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\concurrent;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\concurrent\LocalLock;
use dev\winterframework\util\concurrent\Lock;
use dev\winterframework\util\concurrent\LockableAspect;

#[Attribute(Attribute::TARGET_METHOD)]
class Lockable implements AopStereoType {
    private WinterAspect $aspect;

    public function __construct(
        public string $name,
        public string $provider = LocalLock::class
    ) {
        TypeAssert::objectOfIsA($this->provider, Lock::class);
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->aspect)) {
            $this->aspect = new LockableAspect();
        }
        return $this->aspect;
    }


}