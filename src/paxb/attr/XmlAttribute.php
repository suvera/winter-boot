<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use DateTime;
use DateTimeInterface;
use dev\winterframework\paxb\XmlStereoTypeTrait;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class XmlAttribute implements XmlStereoType {
    use XmlStereoTypeTrait;
    use StereoTypeValidations;

    private RefProperty $refOwner;

    public function __construct(
        protected string $name = '',
        protected bool $required = false,
        protected string $namespace = '',
        protected string $valueAdapter = '',
        protected array $filters = []
    ) {
        if ($this->valueAdapter) {
            $this->validateValueAdapter($this->valueAdapter, 'XmlAttribute');
        }
    }

    public function getFilters(): array {
        return $this->filters;
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function getName(): string {
        return $this->name;
    }

    public function isRequired(): bool {
        return $this->required;
    }

    public function getNamespace(): string {
        return $this->namespace;
    }

    public function getValueAdapter(): string {
        return $this->valueAdapter;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlAttribute');

        $this->assertParameterOfType(
            $ref->getDelegate(),
            'XmlAttribute',
            ['int', 'bool', 'string', 'float', DateTimeInterface::class, DateTime::class]
        );

        $this->cannotBeCombinedWith(
            $ref,
            'XmlAttribute',
            'Xml',
            [XmlStereoType::class]
        );

        $this->refOwner = $ref;
        if ($this->name == '') {
            $this->name = $this->findName($ref);
        }
    }

}