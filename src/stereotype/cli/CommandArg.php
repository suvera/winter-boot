<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\cli;

use Attribute;
use dev\winterframework\core\data\provider\OptionsProvider;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class CommandArg implements StereoType {
    public string $name;
    public bool $required;
    public string $description;
    public array $options;
    /**
     * must be className and must be derived from "OptionsProvider" interface
     * @var string
     */
    public string $optionsProvider;
    public string $type;

    public function __construct(
        string $name = '',
        bool $required = true,
        string $description = '',
        array $options = [],
        string $optionsProvider = ''
    ) {
        $this->name = $name;
        $this->required = $required;
        $this->description = $description;
        $this->options = $options;
        $this->optionsProvider = $optionsProvider;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        if (trim($this->name) === '') {
            $this->name = $ref->getName();
        }

        if (!$ref->hasType()) {
            $type = 'string';
        } else {
            $t = $ref->getType();
            /** @var ReflectionNamedType $type */
            $type = $t->getName();
        }
        $this->type = $type;

        if ($type !== 'string' && $type !== 'int' && $type !== 'bool' && $type !== 'float') {
            throw new TypeError("Command Argument {$this->name} data type must be one of "
                . "[string, int, float, bool], but given $type");
        }

        if ($this->optionsProvider && !is_a($this->optionsProvider, OptionsProvider::class, true)) {
            throw new TypeError("Command Argument {$this->name}  optionsProvider class must be derived from "
                . OptionsProvider::class . ", but it is not");
        }

    }
}