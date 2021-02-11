<?php
declare(strict_types=1);

namespace examples\MyApp\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\StereoTyped;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_METHOD)]
#[StereoTyped]
class MyAspect implements AopStereoType {
    private MyAspectInterceptor $interceptor;

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->interceptor)) {
            $this->interceptor = new MyAspectInterceptor();
        }
        return $this->interceptor;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
    }

}