<?php

declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use DateTime;
use DateTimeInterface;
use dev\winterframework\exception\NullPointerException;
use dev\winterframework\io\ObjectMapper;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\reflection\ref\RefKlass;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use TypeError;

class ParameterType {
    public static ParameterType $noType;

    private bool $unionType = false;
    /**
     * @var ParameterType[]
     */
    private array $unionTypes = [];

    private array $names = [];

    public function __construct(
        private string $name,
        private bool $allowsNull,
        private bool $isBuiltin
    ) {
    }

    public static function getNoType(): ParameterType {
        if (!isset(self::$noType)) {
            self::$noType = new ParameterType('', true, false);
        }
        return self::$noType;
    }

    public static function fromType(
        ReflectionNamedType|ReflectionUnionType|ReflectionType|null $type
    ): ParameterType {
        if ($type == null) {
            return self::getNoType();
        } else if ($type instanceof ReflectionUnionType) {
            return self::fromUnionType($type);
        } else {
            return self::fromNamedType($type);
        }
    }

    public static function fromNamedType(ReflectionNamedType $type): ParameterType {
        $obj = new ParameterType($type->getName(), $type->allowsNull(), $type->isBuiltin());
        $obj->names[] = $type->getName();
        return $obj;
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
            $primary->names[] = $typeObj->getName();

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

    public function isMixedType(): bool {
        return $this->name == 'mixed';
    }

    public function isVoidType(): bool {
        return strtolower($this->name) == 'void';
    }

    public function isStringType(): bool {
        return strtolower($this->name) == 'string';
    }

    public function isIntegerType(): bool {
        return strtolower($this->name) == 'int';
    }

    public function isFloatType(): bool {
        return strtolower($this->name) == 'float';
    }

    public function isBooleanType(): bool {
        return strtolower($this->name) == 'bool' || strtolower($this->name) == 'false' || strtolower($this->name) == 'true';
    }

    public function isArrayType(): bool {
        return strtolower($this->name) == 'array' || (is_a($this->name, \ArrayAccess::class, true) && is_a($this->name, \IteratorAggregate::class, true));
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

    public function getNames(): array {
        return $this->names;
    }

    /** @return ParameterType[] */
    public function getUnionTypes(): array {
        return $this->unionTypes;
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

    public function castValue(
        mixed $value,
        int $source = 0,
        mixed $defaultValue = null
    ): mixed {
        if (is_null($value)) {
            if (!$this->allowsNull() && is_null($defaultValue)) {
                throw new NullPointerException(
                    'Property "'
                        . $this->name . '" cannot be nullable '
                );
            }
            return $defaultValue;
        }

        /**
         * Handle Integer
         */
        if (is_int($value)) {
            if ($this->hasType("int")) {
                return $value;
            } else if ($this->hasType("string")) {
                return strval($value);
            }
            $this->throwTypeError('INTEGER');
        }

        /**
         * Handle Float
         */
        if (is_float($value)) {
            if ($this->hasType("float")) {
                return $value;
            } else if ($this->hasType("string")) {
                return strval($value);
            }
            $this->throwTypeError('FLOAT');
        }

        /**
         * Handle Boolean
         */
        if (is_bool($value)) {
            if ($this->hasType("bool")) {
                return $value;
            }
            $this->throwTypeError('BOOLEAN');
        }

        /**
         * Handle String
         */
        if (is_string($value)) {

            $valLower = strtolower($value);

            if ($this->hasType("string")) {
                return $value;
            } else if ($this->hasType("bool") && ($valLower === 'true' || $valLower === 'false')) {
                return ($valLower === 'true');
            } else if (is_numeric($value)) {
                $value = $value + 0;
                if (is_float($value) && $this->hasType("float")) {
                    return $value;
                } else if ($this->hasType("int")) {
                    return $value;
                }
            } else if ($this->isDateTimeType()) {
                try {
                    return new DateTime($value);
                }
                /** @noinspection PhpUnusedLocalVariableInspection */
                catch (Throwable $e) {
                    // do nothing
                }
            }
            $this->throwTypeError('STRING');
        }

        /**
         * Handle Array
         */
        if (is_array($value)) {
            if ($this->hasType("array")) {
                return $value;
            }

            $classTypes = $this->getClassTypes();
            foreach ($classTypes as $classType) {
                $cls = RefKlass::getInstance($classType);
                if ($cls->isInstantiable()) {
                    if ($source == ObjectMapper::SOURCE_JSON || $source == ObjectMapper::SOURCE_ARRAY) {
                        return ObjectCreator::createObject($classType, $value);
                    }
                }
            }
            $this->throwTypeError('ARRAY');
        }

        /**
         * Handle Object
         */
        if (is_object($value)) {
            if ($this->hasType("object")) {
                return $value;
            }

            $classTypes = $this->getClassTypes();
            foreach ($classTypes as $classType) {
                if ($value instanceof $classType) {
                    return $value;
                }
            }
            $this->throwTypeError('OBJECT');
        }

        /**
         * Handle Resource
         */
        if (is_resource($value)) {
            if ($this->hasType("mixed")) {
                return $value;
            }
            $this->throwTypeError('RESOURCE');
        }

        // is_callable() - ignored
        $this->throwTypeError('unknown');
        return null;
    }

    private function throwTypeError(
        string $type = ''
    ): void {
        throw new TypeError(
            'Parameter "' . $this->name
                . '" cannot be assigned to "' . $type . '"'
        );
    }
}
