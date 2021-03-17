<?php /** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\reflection\support\ParameterType;
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

        $type = ParameterType::fromType($ref->getType());

        if ($type->isNoType() || $type->hasType('string')) {
            $this->variableType = 'string';
        } else if ($type->hasType('int')) {
            $this->variableType = 'int';
        } else if ($type->hasType('float')) {
            $this->variableType = 'float';
        } else if ($type->hasType('bool')) {
            $this->variableType = 'bool';
        } else {
            throw new InvalidSyntaxException(
                '#[PathVariable] annotated variable must be a scalar type (string|int|float|bool), '
                . 'param: ' . $ref->getName() . ', method '
                . $ref->getDeclaringFunction()->getName()
            );
        }
    }

}