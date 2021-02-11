<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class PostConstruct implements StereoType {
    private RefMethod $refOwner;

    public function __construct() {
    }

    public function getRefOwner(): RefMethod {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        if ($ref->hasReturnType()) {
            throw new TypeError("Method Annotated with #[PostConstruct] must not have any return type"
                . ReflectionUtil::getFqName($ref));
        }

        if ($ref->getNumberOfRequiredParameters() > 0) {
            throw new TypeError("Method Annotated with #[PostConstruct] must not have any required parameters"
                . ReflectionUtil::getFqName($ref));
        }

        $this->refOwner = $ref;
    }
}