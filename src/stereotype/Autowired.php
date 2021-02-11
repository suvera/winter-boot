<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Autowired implements StereoType {

    private RefProperty $refOwner;
    private string $targetType;
    private string $targetName;
    private bool $targetStatic;
    private RefKlass $targetClass;

    public function __construct(
        public ?string $name = null
    ) {
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function getTargetName(): string {
        return $this->targetName;
    }

    public function getTargetClass(): RefKlass {
        return $this->targetClass;
    }

    public function isTargetStatic(): bool {
        return $this->targetStatic;
    }

    public function getTargetType(): string {
        return $this->targetType;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);
        $this->refOwner = $ref;

        if (!$this->refOwner->hasType()) {
            throw new TypeError('Property ' . $this->refOwner->getName() . ' must define with a type');
        }

        /** @var ReflectionNamedType $type */
        $type = $this->refOwner->getType();
        if ($type instanceof ReflectionUnionType) {
            throw new TypeError('Property ' . $this->refOwner->getName()
                . ' cannot be autowired with UNION data types.');
        }
        if ($type->isBuiltin()) {
            throw new TypeError('Property ' . $this->refOwner->getName()
                . ' cannot be autowired with built-in data type.');
        }

        $this->targetStatic = $this->refOwner->isStatic();
        $this->targetName = $this->refOwner->getName();
        $this->targetType = $type->getName();

        try {
            $this->targetClass = new RefKlass($this->targetType);
        } catch (Throwable $e) {
            throw new TypeError('Property ' . $this->refOwner->getName()
                . ' cannot be autowired as class could not be loaded ', 0, $e);
        }

        /**
         * Interfaces can be used in Autowired places
         * so, we cannot check it's implementation exist at this time.
         */

//        if (!$this->targetClass->isInstantiable()
//            && !AutowiredInternalClasses::contains($this->targetClass->getName())) {
//            throw new TypeError('Property ' . ReflectionUtil::getFqName($ref)
//                . ' cannot be autowired as this class cannot be Instantiable.');
//        }

    }
}