<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\stereotype\Module;
use dev\winterframework\core\app\WinterCliArguments;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;

interface ApplicationContext {
    public function getId(): string;

    public function getApplicationName(): string;

    public function getApplicationVersion(): string;

    public function getStartupDate(): int;

    public function beanByName(string $name): mixed;

    public function beanByClass(string $class): mixed;

    public function beanByNameClass(string $name, string $class): mixed;

    public function hasBeanByName(string $name): bool;

    public function hasBeanByClass(string $class): bool;

    public function getProperty(string $name, mixed $default = null): mixed;

    public function getPropertyStr(string $name, ?string $default = null): string;

    public function getPropertyBool(string $name, ?bool $default = null): bool;

    public function getPropertyInt(string $name, ?int $default = null): int;

    public function getPropertyFloat(string $name, ?float $default = null): float;

    public function setProperty(string $name, mixed $value): mixed;

    public function addClass(string $class): ClassResource;

    public function getProperties(): array;

    public function hasModule(string $moduleName): bool;

    public function getModule(string $moduleName): Module;

    public function getModules(): array;

    public function getCliArgs(): WinterCliArguments;

    /**
     * Returns the HttpRequest currently being dispatched, or null when
     * no HTTP request is in flight (CLI apps, tests, outside dispatch).
     */
    public function getCurrentHttpRequest(): ?HttpRequest;

    /**
     * @internal Framework use only — bound by the dispatcher per request.
     */
    public function setCurrentHttpRequest(?HttpRequest $request): void;

    /**
     * Returns the ResponseEntity currently being dispatched, or null when
     * no HTTP request is in flight (CLI apps, tests, outside dispatch).
     */
    public function getCurrentHttpResponse(): ?ResponseEntity;

    /**
     * @internal Framework use only — bound by the dispatcher per request.
     */
    public function setCurrentHttpResponse(?ResponseEntity $response): void;

}