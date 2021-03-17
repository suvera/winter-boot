<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestParam implements StereoType {

    private string $variableName;
    private ParameterType $variableType;

    public function __construct(
        public string $name = '',
        public bool $required = true,
        public mixed $defaultValue = null,
        // 'request', 'get', 'post', 'cookie', 'header'
        public string $source = 'request'
    ) {
    }

    public function getVariableType(): ParameterType {
        return $this->variableType;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function getVariableName(): string {
        return $this->variableName;
    }

    public function init(object $ref): void {
        /** @var RefParameter $ref */
        TypeAssert::typeOf($ref, RefParameter::class);

        if (trim($this->name) == '') {
            $this->name = $ref->getName();
        }

        $this->variableName = $ref->getName();

        if ($ref->isDefaultValueAvailable() && $this->defaultValue == null) {
            $this->defaultValue = $ref->getDefaultValue();
        }

        $type = ParameterType::fromType($ref->getType());

        if ($type->isNoType()) {
            $this->variableType = new ParameterType('string', true, true);
        } else {
            $this->variableType = $type;
        }

        $prefix = 'Parameter annotated with #[RequestParam] ';
        if (!$type->isBuiltin()) {
            throw new InvalidSyntaxException(
                $prefix
                . ' cannot be custom Type '
                . 'param: ' . $ref->getName() . ' defined in method '
                . $ref->getDeclaringFunction()->getName()
            );
        }

        switch ($this->source) {
            case 'header':
                TypeAssert::isScalarOrArrayName($type->getName(), $prefix);
                break;

            default:
                TypeAssert::isScalarName($type->getName(), $prefix);
                break;
        }
    }


    public function getRequiredText(): string {
        return match ($this->getSource()) {
            'get' => 'parameter "' . $this->name . '" is required in Query String.',
            'post' => 'parameter "' . $this->name . '" is required in POST url-encoded/form-body.',
            'cookie' => 'Cookie "' . $this->name . '" not set.',
            'header' => 'Header  "' . $this->name . '" is required.',
            default => 'parameter "' . $this->name . '" is required.',
        };
    }

    public function getInvalidText(): string {
        return match ($this->getSource()) {
            'get' => 'Invalid value for parameter "' . $this->name . '" in Query String.',
            'post' => 'Invalid value for parameter "' . $this->name
                . '" in POST url-encoded/form-body.',
            'cookie' => 'Invalid value for Cookie "' . $this->name . '".',
            'header' => 'Invalid value for Header  "' . $this->name . '".',
            default => 'Invalid value for parameter "' . $this->name . '".',
        };
    }
}