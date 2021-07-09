<?php

declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\TypeAssert;
use ReflectionUnionType;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Value implements StereoType {
    private string $targetName;
    private string $targetType = '';
    private bool $nullable = false;
    private RefProperty $refOwner;
    private bool $targetStatic = false;

    public function __construct(
        public string $name,
        public int|float|string|bool|null|array $defaultValue = null
    ) {
        $this->name = trim($this->name);
    }

    public function getTargetName(): string {
        return $this->targetName;
    }

    public function getTargetType(): string {
        return $this->targetType;
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function isNullable(): bool {
        return $this->nullable;
    }

    public function isTargetStatic(): bool {
        return $this->targetStatic;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        if (!str_starts_with($this->name, '${') || !str_ends_with($this->name, '}')) {
            throw new TypeError('Annotation #[Value] name must start with "${" and end with "}"'
                . ' for Parameter ' . $ref->getName() . ', on the class '
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }

        if ($ref->hasType()) {
            $type = $ref->getType();

            if ($type instanceof ReflectionUnionType) {
                throw new TypeError('Parameter ' . $ref->getName()
                    . ' must be defined with scalar data type on the class'
                    . ReflectionUtil::getFqName($ref->getDeclaringClass())
                );
            } else if (!$type->isBuiltin()) {
                throw new TypeError('Parameter ' . $ref->getName()
                    . ' must be defined with a scalar data type on the class'
                    . ReflectionUtil::getFqName($ref->getDeclaringClass())
                );
            } else {
                $this->targetType = $type->getName();
            }

            $this->nullable = $type->allowsNull();
        }

        $this->refOwner = $ref;
        $this->targetStatic = $this->refOwner->isStatic();
        $this->targetName = $ref->getName();
        if ($this->defaultValue === null && $ref->hasDefaultValue()) {
            $this->defaultValue = $ref->getDefaultValue();
        }
    }
}