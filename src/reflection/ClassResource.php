<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\type\AttributeList;

class ClassResource {
    private RefKlass $class;
    private MethodResources $methods;
    private VariableResources $variables;
    private AttributeList $attributes;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getMethod(string $name): ?MethodResource {
        return $this->methods->getMethod($name);
    }

    public function getClass(): RefKlass {
        return $this->class;
    }

    public function setClass(RefKlass $class): void {
        $this->class = $class;
    }

    public function getMethods(): MethodResources {
        return $this->methods;
    }

    public function setMethods(MethodResources $methods): void {
        $this->methods = $methods;
    }

    public function getVariables(): VariableResources {
        return $this->variables;
    }

    public function setVariables(VariableResources $variables): void {
        $this->variables = $variables;
    }

    public function getAttributes(): AttributeList {
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }

}