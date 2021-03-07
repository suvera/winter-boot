<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\type\AttributeList;

class VariableResource {
    private RefProperty $variable;
    private AttributeList $attributes;
    private ParameterType $parameterType;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getType(): string {
        return $this->getParameterType()->getName();
    }

    public function getParameterType(): ParameterType {
        if (!isset($this->parameterType)) {
            $this->parameterType = ParameterType::fromType($this->variable->getType());
        }
        return $this->parameterType;
    }

    public function getVariable(): RefProperty {
        return $this->variable;
    }

    public function setVariable(RefProperty $variable): void {
        $this->variable = $variable;
    }

    /**
     * @return AttributeList|object[]
     * @noinspection PhpDocSignatureInspection
     */
    public function getAttributes(): AttributeList {
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }

}