<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\test;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class WinterBootTest implements StereoType {

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);
    }

}