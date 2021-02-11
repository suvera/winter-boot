<?php
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\type\AttributeList;
use ReflectionNamedType;

class MethodResource {
    private ?ClassResource $returnClass;

    private ReflectionSafeType $returnType;

    private RefMethod $method;

    private AttributeList $attributes;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getReturnNamedType(): ReflectionSafeType {
        if (!isset($this->returnType)) {
            /** @var ReflectionNamedType $type */
            $type = $this->method->getReturnType();
            if ($type == null) {
                $this->returnType = ReflectionSafeType::getNoType();
            } else {
                $this->returnType = ReflectionSafeType::fromNamedType($type);
            }
        }
        return $this->returnType;
    }

    public function getReturnType(): string {
        return $this->getReturnNamedType()->getName();
    }

    public function getReturnClass(): ?ClassResource {
        return $this->returnClass;
    }

    public function setReturnClass(?ClassResource $returnClass): void {
        $this->returnClass = $returnClass;
    }

    public function getMethod(): RefMethod {
        return $this->method;
    }

    public function setMethod(RefMethod $method): void {
        $this->method = $method;
    }

    public function getAttributes(): AttributeList {
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }


}