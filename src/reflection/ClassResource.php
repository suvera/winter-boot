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
    private bool $proxyNeeded = false;
    private MethodResources $proxyMethods;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getAttributesBy(string $name): array {
        return isset($this->attributes) ? $this->attributes->getByNames($name) : [];
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

    /**
     * @return MethodResources|MethodResource[]
     * @noinspection PhpDocSignatureInspection
     */
    public function getMethods(): MethodResources {
        return $this->methods;
    }

    public function setMethods(MethodResources $methods): void {
        $this->methods = $methods;
    }

    /**
     * @return VariableResources|VariableResource[]
     * @noinspection PhpDocSignatureInspection
     */
    public function getVariables(): VariableResources {
        return $this->variables;
    }

    public function setVariables(VariableResources $variables): void {
        $this->variables = $variables;
    }

    public function getAttributes(): AttributeList {
        if (!isset($this->attributes)) {
            $this->attributes = AttributeList::ofValues();
        }
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }

    public function isProxyNeeded(): bool {
        return $this->proxyNeeded;
    }

    public function setProxyNeeded(bool $proxyNeeded): void {
        $this->proxyNeeded = $proxyNeeded;
    }

    public function getProxyMethods(): MethodResources {
        if (!isset($this->proxyMethods)) {
            $this->proxyMethods = MethodResources::emptyList();
        }
        return $this->proxyMethods;
    }

    public function setProxyMethods(MethodResources $proxyMethods): void {
        $this->proxyMethods = $proxyMethods;
    }

}