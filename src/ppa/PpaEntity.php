<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\ppa;

interface PpaEntity {
    public function isCreatable(): bool;

    public function isUpdatable(): bool;

    public function isStored(): bool;

    public function setStored(bool $saved): void;

    public function setNullValue(string $propName): void;

    public function hasNullValue(string $propName): bool;

    public function clearNullValue(string $propName): void;

    public function clearNullValues(): void;
}