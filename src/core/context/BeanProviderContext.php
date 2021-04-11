<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;

interface BeanProviderContext {

    public function addProviderClass(ClassResource $class): void;

    public function addProviderClassAs(ClassResource $class, array $attributes): void;

    public function addProviderMethod(ClassResource $class, MethodResource $method): void;

    public function beanByName(string $name): ?object;

    public function beanByClass(string $class): ?object;

    public function beanByNameClass(string $name, string $class): ?object;

    public function hasBeanByName(string $name): bool;

    public function hasBeanByClass(string $class): bool;
}