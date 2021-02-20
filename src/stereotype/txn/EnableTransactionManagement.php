<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\txn;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_CLASS)]
class EnableTransactionManagement implements StereoType {

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $app = $ref->getAttributes(WinterBootApplication::class);

        if (empty($app)) {
            throw new TypeError("Class Annotated with #[EnableTransactionManagement] "
                . "must have also annotated with #[WinterBootApplication] for class "
                . ReflectionUtil::getFqName($ref));
        }
    }

}