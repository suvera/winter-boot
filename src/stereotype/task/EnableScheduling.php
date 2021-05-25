<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\task;

use Attribute;
use dev\winterframework\exception\AnnotationException;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_CLASS)]
class EnableScheduling implements StereoType {

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $app = $ref->getAttributes(WinterBootApplication::class);

        if (empty($app)) {
            throw new TypeError("Class Annotated with #[EnableScheduling] "
                . "must have also annotated with #[WinterBootApplication] for class "
                . ReflectionUtil::getFqName($ref));
        }

        if (!extension_loaded('swoole')) {
            throw new AnnotationException("Annotation #[EnableScheduling] requires *swoole* extension in PHP runtime "
                . ReflectionUtil::getFqName($ref));
        }
    }

}