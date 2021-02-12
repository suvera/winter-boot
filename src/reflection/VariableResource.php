<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\type\AttributeList;
use ReflectionNamedType;

class VariableResource {
    private RefProperty $variable;
    private AttributeList $attributes;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getType(): string {
        /** @var ReflectionNamedType $type */
        $type = $this->variable->getType();
        if ($type == null) {
            return '';
        }
        return $type->getName();
    }

    public function getVariable(): RefProperty {
        return $this->variable;
    }

    public function setVariable(RefProperty $variable): void {
        $this->variable = $variable;
    }

    public function getAttributes(): AttributeList {
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }
    
}