<?php

declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\validate\FieldValidator;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class JsonProperty implements StereoType {
    protected string $objectClass = '';
    protected ParameterType $paramType;

    public function __construct(
        public array|string $name = '',
        protected bool $required = false,
        protected bool $nillable = false,
        protected string $listClass = '',
        protected array $validate = [],
    ) {
    }

    public function isList(): bool {
        return !empty($this->listClass);
    }

    public function getListClass(): string {
        return $this->listClass;
    }

    public function isObject(): bool {
        return !empty($this->objectClass);
    }

    public function getObjectClass(): string {
        return $this->objectClass;
    }

    public function isRequired(): bool {
        return $this->required;
    }

    public function isNillable(): bool {
        return $this->nillable;
    }

    public function getName(): array|string {
        return $this->name;
    }

    public function getParamType(): ParameterType {
        return $this->paramType;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);
        if (empty($this->name)) {
            $this->name = $ref->getName();
        }
        $targetClass = $ref->getDeclaringClass()->getName();
        $type = $this->paramType = ParameterType::fromType($ref->getType());

        if ($ref->isStatic()) {
            throw new TypeError('JsonProperty] Property ' . $ref->getName()
                . ' cannot be static, in the class ' . $targetClass);
        }

        if ($type->allowsNull()) {
            $this->nillable = true;
        }
        if (!$ref->hasDefaultValue() && !$type->allowsNull()) {
            $this->required = true;
        }

        if ($type->isNoType() || $type->isVoidType() || $type->isMixedType()) {
            throw new TypeError('JsonProperty] Property ' . $ref->getName()
                . ' must define with a type, in the class ' . $targetClass);
        } else if ($type->isUnionType()) {
            foreach ($type->getUnionTypes() as $t) {
                if (!$t->isBuiltin() || $t->isVoidType() || $t->isNoType() || $type->isMixedType()) {
                    throw new TypeError('[JsonProperty] Property ' . $ref->getName()
                        . ' must be defined with built-in data type, in the class ' . $targetClass);
                }
            }
            throw new TypeError('[JsonProperty] Property ' . $ref->getName()
                . ' cannot be validated with UNION data types, in the class ' . $targetClass);
        } else if (!$type->isBuiltin()) {
            $this->objectClass = $type->getName();
        }
    }

    public function validate(string $paramName, mixed $value): ?string {
        if ($this->required) {
            if ($value === null || $value === '' || $value === []) {
                return 'Property ' . ($paramName ? $paramName : $this->name) . ' is required';
            }
        }

        $errors = [];
        foreach ($this->validate as $checkDef) {
            if (is_string($checkDef)) {
                $msg = FieldValidator::getInstance()->validate($checkDef, $paramName, $this->paramType, $value, []);
            } else if (is_array($checkDef) && count($checkDef) > 0) {
                $checkName = $checkDef[0];
                $msg = FieldValidator::getInstance()->validate($checkName, $paramName, $this->paramType, $value, $checkDef);
            } else {
                $msg = 'Property ' . $paramName ? $paramName : $this->name . ' is defined with an invalid validator type';
            }

            if ($msg) {
                $errors[] = $msg;
            }
        }

        return implode(". \n", $errors);
    }
}
