<?php /** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\reflection;

use ReflectionNamedType;

class ReflectionSafeType {
    public static ReflectionSafeType $noType;

    public function __construct(
        private string $name,
        private bool $allowsNull,
        private bool $isBuiltin,
    ) {
    }

    public static function getNoType(): ReflectionSafeType {
        if (!isset(self::$noType)) {
            self::$noType = new ReflectionSafeType('', false, false);
        }
        return self::$noType;
    }

    public static function fromNamedType(ReflectionNamedType $type): ReflectionSafeType {
        return new ReflectionSafeType($type->getName(), $type->allowsNull(), $type->isBuiltin());
    }

    public function isNoType(): bool {
        return $this->name == '';
    }

    public function isVoidType(): bool {
        return strtolower($this->name) == 'void';
    }

    public function allowsNull(): bool {
        return $this->allowsNull;
    }

    public function __toString(): string {
        return $this->name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function isBuiltin(): bool {
        return $this->isBuiltin;
    }
}