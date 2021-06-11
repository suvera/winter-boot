<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\ppa;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableGenerator implements StereoType {
    use StereoTypeValidations;

    protected string $varName;

    public function __construct(
        protected string $tableName,
        protected string $pkColumnName,
        protected string $pkColumnValue,
        protected string $valueColumnName,
        protected int $allocationSize = 1
    ) {
    }

    public function getVarName(): string {
        return $this->varName;
    }

    /**
     * @return string
     */
    public function getTableName(): string {
        return $this->tableName;
    }

    /**
     * @return string
     */
    public function getPkColumnName(): string {
        return $this->pkColumnName;
    }

    /**
     * @return string
     */
    public function getPkColumnValue(): string {
        return $this->pkColumnValue;
    }

    /**
     * @return string
     */
    public function getValueColumnName(): string {
        return $this->valueColumnName;
    }

    /**
     * @return int
     */
    public function getAllocationSize(): int {
        return $this->allocationSize;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::objectOf($ref, RefProperty::class);

        if ($this->allocationSize < 1) {
            $this->allocationSize = 1;
        }

        $this->varName = $ref->getName();

        $others = $ref->getAttributes(SequenceGenerator::class);
        if (!$others) {
            throw new TypeError('Parameter ' . $ref->getName()
                . ' cannot have two generators at same time '
                . ReflectionUtil::getFqName($ref->getDeclaringClass())
            );
        }
    }
}