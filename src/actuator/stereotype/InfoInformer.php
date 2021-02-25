<?php
declare(strict_types=1);

namespace dev\winterframework\actuator\stereotype;

use Attribute;
use dev\winterframework\actuator\InfoContributor;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_CLASS)]
class InfoInformer implements StereoType {

    public function __construct() {
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        if (!$ref->implementsInterface(InfoContributor::class)) {
            throw new TypeError('HealthInformer class '
                . ReflectionUtil::getFqName($ref)
                . ' must implement InfoContributor');
        }
    }
}