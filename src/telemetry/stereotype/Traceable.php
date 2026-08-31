<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\stereotype;

use Attribute;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\telemetry\aop\TraceableAspect;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\reflection\ref\RefMethod;

#[Attribute(Attribute::TARGET_METHOD)]
#[dev\winterframework\stereotype\aop\StereoTyped]
class Traceable implements AopStereoType {
    use StereoTypeValidations;

    private ?TraceableAspect $interceptor = null;

    public function __construct(
        public string $name = ''
    ) {
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!$this->interceptor) {
            $this->interceptor = new TraceableAspect();
        }
        return $this->interceptor;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
        $this->validateAopMethod($ref, 'Traceable');
    }
}
