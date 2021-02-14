<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use ReflectionNamedType;
use ReflectionUnionType;

class ReflectionSafeType {
    public static ReflectionSafeType $noType;

    private bool $unionType = false;
    /**
     * @var ReflectionSafeType[]
     */
    private array $unionTypes = [];

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

    public static function fromUnionType(ReflectionUnionType $type): ReflectionSafeType {
        $primary = null;
        foreach ($type->getTypes() as $subType) {
            $typeObj = new ReflectionSafeType($subType->getName(), $subType->allowsNull(), $subType->isBuiltin());
            if (is_null($primary)) {
                $typeObj->unionType = true;
                $primary = $typeObj;
            }
            $primary->unionTypes[] = $typeObj;
        }

        return $primary;
    }

    public function isUnionType(): bool {
        return $this->unionType;
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