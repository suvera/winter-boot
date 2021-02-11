<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;
use TypeError;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Qualifier implements StereoType {

    private string $variableType;

    private RefParameter $refOwner;

    private mixed $defaultValue = null;

    public function __construct(
        public string $name
    ) {
    }

    public function getVariableType(): string {
        return $this->variableType;
    }

    public function getDefaultValue(): mixed {
        return $this->defaultValue;
    }

    public function getRefOwner(): RefParameter {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefParameter $ref */
        TypeAssert::typeOf($ref, RefParameter::class);
        $this->refOwner = $ref;

        if (!$ref->hasType()) {
            throw new TypeError('Parameter ' . $this->refOwner->getName()
                . ' must define with a type in the method '
                . ReflectionUtil::getFqName($ref->getDeclaringFunction())
            );
        }

        /** @var ReflectionNamedType $type */
        $type = $ref->getType();
        if ($type->isBuiltin()) {
            throw new TypeError('Parameter ' . ReflectionUtil::getFqName($ref)
                . ' cannot be autowired with built-in data type in the method '
                . ReflectionUtil::getFqName($ref->getDeclaringFunction())
            );
        }

        if ($ref->isDefaultValueAvailable()) {
            $this->defaultValue = $ref->getDefaultValue();
        }

        $this->variableType = $type->getName();
    }
}