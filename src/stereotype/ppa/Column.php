<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\ppa;

use Attribute;
use DateTime;
use DateTimeInterface;
use dev\winterframework\pdbc\core\BindType;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use ReflectionUnionType;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column implements StereoType {
    use StereoTypeValidations;

    protected mixed $defaultValue = null;
    protected int $type = BindType::STRING;
    protected string $varName;

    public function __construct(
        protected string $name = '',
        protected int $length = 0,
        protected int $precision = 0,
        protected int $scale = 0,
        protected bool $nullable = true,
        protected bool $insertable = true,
        protected bool $updatable = true,
        protected bool $id = false
    ) {
    }

    /**
     * @return mixed
     */
    public function getDefaultValue(): mixed {
        return $this->defaultValue;
    }

    /**
     * @return int
     */
    public function getType(): int {
        return $this->type;
    }

    /**
     * @return bool
     */
    public function isNullable(): bool {
        return $this->nullable;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getLength(): int {
        return $this->length;
    }

    /**
     * @return int
     */
    public function getPrecision(): int {
        return $this->precision;
    }

    /**
     * @return int
     */
    public function getScale(): int {
        return $this->scale;
    }

    /**
     * @return bool
     */
    public function isInsertable(): bool {
        return $this->insertable;
    }

    /**
     * @return bool
     */
    public function isUpdatable(): bool {
        return $this->updatable;
    }

    /**
     * @return bool
     */
    public function isId(): bool {
        return $this->id;
    }

    public function getVarName(): string {
        return $this->varName;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::objectOf($ref, RefProperty::class);

        if (!$this->name) {
            $this->name = $ref->getName();
        }
        $this->varName = $ref->getName();

        if ($ref->isStatic()) {
            throw new TypeError('Parameter ' . $ref->getName()
                . ' cannot be static on the class'
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }

        if (!$ref->hasType()) {
            throw new TypeError('Parameter ' . $ref->getName()
                . ' must be defined with a data type on the class'
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }

        $type = $ref->getType();
        if ($type instanceof ReflectionUnionType) {
            throw new TypeError('Parameter ' . $ref->getName()
                . ' must be defined with a single data type (not by Union types) on the class'
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }

        $typeName = $type->getName();
        if ($typeName == 'string' || $typeName == 'mixed') {
            $this->type = BindType::STRING;
        } else if (is_a($typeName, DateTime::class, true)
            || is_a($typeName, DateTimeInterface::class, true)
        ) {
            $this->type = BindType::DATE;
        } else if ($typeName == 'int') {
            $this->type = BindType::INTEGER;
        } else if ($typeName == 'float') {
            $this->type = BindType::FLOAT;
        } else if ($typeName == 'bool') {
            $this->type = BindType::BOOL;
        } else if (is_a($typeName, Blob::class, true)) {
            $this->type = BindType::BLOB;
        } else if (is_a($typeName, Clob::class, true)) {
            $this->type = BindType::CLOB;
        } else {
            throw new TypeError('Parameter ' . $ref->getName()
                . ' is defined with unrecognized data type on the class'
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }

        //$this->nullable = ($typeName == 'mixed' || $type->allowsNull());

        if ($ref->hasDefaultValue()) {
            $this->defaultValue = $ref->getDefaultValue();
        }
    }

}