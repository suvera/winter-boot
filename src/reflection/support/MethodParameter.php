<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use ReflectionParameter;

class MethodParameter {
    private string $name;
    private ParameterType $type;
    private bool $defaultValue = false;
    private bool $defaultValueConstant = false;
    private mixed $defaultValueValue;
    private bool $allowsNull = false;
    private int $position;
    private bool $optional = false;
    private bool $passedByReference = false;

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getType(): ParameterType {
        return $this->type;
    }

    public function setType(ParameterType $type): void {
        $this->type = $type;
    }

    public function hasDefaultValue(): bool {
        return $this->defaultValue;
    }

    public function setDefaultValue(bool $defaultValue): void {
        $this->defaultValue = $defaultValue;
    }

    public function hasDefaultValueConstant(): bool {
        return $this->defaultValueConstant;
    }

    public function setDefaultValueConstant(bool $defaultValueConstant): void {
        $this->defaultValueConstant = $defaultValueConstant;
    }

    public function getDefaultValueValue(): mixed {
        return $this->defaultValueValue;
    }

    public function setDefaultValueValue(mixed $defaultValueValue): void {
        $this->defaultValueValue = $defaultValueValue;
    }

    public function isAllowsNull(): bool {
        return $this->allowsNull;
    }

    public function setAllowsNull(bool $allowsNull): void {
        $this->allowsNull = $allowsNull;
    }

    public function getPosition(): int {
        return $this->position;
    }

    public function setPosition(int $position): void {
        $this->position = $position;
    }

    public function isOptional(): bool {
        return $this->optional;
    }

    public function setOptional(bool $optional): void {
        $this->optional = $optional;
    }

    public function isPassedByReference(): bool {
        return $this->passedByReference;
    }

    public function setPassedByReference(bool $passedByReference): void {
        $this->passedByReference = $passedByReference;
    }

    public static function fromReflection(ReflectionParameter $param): self {
        $p = new MethodParameter();

        $p->setName($param->getName());
        $p->setType(ParameterType::fromType($param->getType()));

        if ($param->isDefaultValueAvailable()) {
            $p->setDefaultValue(true);
            if ($param->isDefaultValueConstant()) {
                $p->setDefaultValueConstant(true);
                /** @noinspection PhpUnhandledExceptionInspection */
                $p->setDefaultValueValue($param->getDefaultValueConstantName());
            } else {
                /** @noinspection PhpUnhandledExceptionInspection */
                $p->setDefaultValueValue($param->getDefaultValue());
            }
        }

        $p->allowsNull = $param->allowsNull();
        $p->position = $param->getPosition();
        $p->optional = $param->isOptional();
        $p->passedByReference = $param->isPassedByReference();

        return $p;
    }
}