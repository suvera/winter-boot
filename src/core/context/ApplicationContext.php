<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

interface ApplicationContext {
    const FACTORY_BEAN_PREFIX = "&";

    public function getId(): string;

    public function getApplicationName(): string;

    public function getStartupDate(): int;

    public function beanByName(string $name): mixed;

    public function beanByClass(string $class): mixed;

    public function beanByNameClass(string $name, string $class): mixed;

    public function hasBeanByName(string $name): bool;

    public function hasBeanByClass(string $class): bool;

}