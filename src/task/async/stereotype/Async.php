<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\task\async\stereotype;

use Attribute;
use dev\winterframework\exception\AnnotationException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class Async implements StereoType {
    use StereoTypeValidations;

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        $stereoName = 'Async';

        if (!extension_loaded('swoole')) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '] requires *swoole* extension in PHP runtime "
                . ReflectionUtil::getFqName($ref));
        }

        $this->cannotBeFinalMethod($ref, $stereoName);
        $this->cannotBeConstructor($ref, $stereoName);
        $this->cannotBeAbstractMethod($ref, $stereoName);
        $this->mustBePublicMethod($ref, $stereoName);
        //$this->mustHaveZeroRequiredArgument($ref, $stereoName);
        $this->cannotHaveReturn($ref, $stereoName);

        foreach ($ref->getParameters() as $param) {
            if (!$param->hasType()) {
                throw new TypeError("#[Async] Parameter ' . $param->name 
                . ' has not type defined at "
                    . ReflectionUtil::getFqName($ref));
            }

            /** @var ReflectionNamedType|ReflectionUnionType|ReflectionType $type */
            $type = $param->getType();

            if ($type instanceof ReflectionUnionType) {
                $types = $type->getTypes();
            } else {
                $types = [$type];
            }

            foreach ($types as $t) {
                if ($t->getName() == 'mixed') {
                    throw new TypeError("#[Async] Parameter ' . $param->name 
                    . ' has typed as 'mixed' at "
                        . ReflectionUtil::getFqName($ref));
                } else if (!($t->getName() == 'int'
                    || $t->getName() == 'float'
                    || $t->getName() == 'string'
                    || $t->getName() == 'bool'
                )) {
                    throw new TypeError("#[Async] Parameter ' . $param->name 
                    . ' has typed as 'complex' at "
                        . ReflectionUtil::getFqName($ref));
                }
            }
        }

    }
}