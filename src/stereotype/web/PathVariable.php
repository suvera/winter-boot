<?php /** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PARAMETER)]
class PathVariable implements StereoType {

    private string $variableName = '';
    private string $variableType = '';

    private RefParameter $refOwner;

    public function __construct(
        public string $name = '',
        public bool $required = true
    ) {
    }

    public function getVariableName(): string {
        return $this->variableName;
    }

    public function getVariableType(): string {
        return $this->variableType;
    }

    public function getRefOwner(): RefParameter {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefParameter $ref */
        TypeAssert::typeOf($ref, RefParameter::class);
        $this->refOwner = $ref;

        if (trim($this->name) == '') {
            $this->name = $ref->getName();
        }

        $this->variableName = $ref->getName();
        $this->variableType = $ref->hasType() ? $ref->getType()->getName() : 'string';
        TypeAssert::isScalar($this->variableType);
    }

}