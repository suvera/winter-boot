<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use DateTime;
use DateTimeInterface;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

class ParameterType {
    public static ParameterType $noType;

    private bool $unionType = false;
    /**
     * @var ParameterType[]
     */
    private array $unionTypes = [];

    public function __construct(
        private string $name,
        private bool $allowsNull,
        private bool $isBuiltin,
    ) {
    }

    public static function getNoType(): ParameterType {
        if (!isset(self::$noType)) {
            self::$noType = new ParameterType('', true, false);
        }
        return self::$noType;
    }

    public static function fromType(ReflectionNamedType|ReflectionUnionType|ReflectionType|null $type): ParameterType {
        if ($type == null) {
            return self::getNoType();
        } else if ($type instanceof ReflectionUnionType) {
            return self::fromUnionType($type);
        } else {
            return self::fromNamedType($type);
        }
    }

    public static function fromNamedType(ReflectionNamedType $type): ParameterType {
        return new ParameterType($type->getName(), $type->allowsNull(), $type->isBuiltin());
    }

    public static function fromUnionType(ReflectionUnionType $type): ParameterType {
        $primary = null;
        foreach ($type->getTypes() as $subType) {
            $typeObj = new ParameterType($subType->getName(), $subType->allowsNull(), $subType->isBuiltin());
            if (is_null($primary)) {
                $typeObj->unionType = true;
                $primary = $typeObj;
            }
            $primary->unionTypes[] = $typeObj;
            if ($subType->allowsNull()) {
                $primary->allowsNull = true;
            }
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

    public function hasType(string $type): bool {
        if ($this->isNoType() || $this->getName() === $type || $this->getName() === 'mixed') {
            return true;
        }
        if ($this->isUnionType()) {
            foreach ($this->unionTypes as $subType) {
                if ($subType->getName() === $type || $subType->getName() === 'mixed') {
                    return true;
                }
            }
        }
        return false;
    }

    public function getClassType(): string {
        if ($this->isNoType()) {
            return '';
        }
        if ($this->isUnionType()) {
            foreach ($this->unionTypes as $subType) {
                if (!$subType->isBuiltin()) {
                    return $subType->getName();
                }
            }
        } else if (!$this->isBuiltin()) {
            return $this->getName();
        }
        return '';
    }

    public function getClassTypes(): array {
        if ($this->isNoType()) {
            return [];
        }
        if ($this->isUnionType()) {
            $ret = [];
            foreach ($this->unionTypes as $subType) {
                if (!$subType->isBuiltin()) {
                    $ret[] = $subType->getName();
                }
            }
            return $ret;
        } else if (!$this->isBuiltin()) {
            return [$this->getName()];
        }
        return [];
    }

    public function isDateTimeType(): bool {
        return $this->hasType(DateTimeInterface::class) || $this->hasType(DateTime::class);
    }

    public function isBuiltin(): bool {
        return $this->isBuiltin;
    }
}