<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class XmlElementWrapper implements XmlStereoType {
    use StereoTypeValidations;

    private RefProperty $refOwner;

    public function __construct(
        protected string $name,
        protected bool $nillable = false,
        protected bool $required = false,
        protected string $namespace = ''
    ) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function isNillable(): bool {
        return $this->nillable;
    }

    public function isRequired(): bool {
        return $this->required;
    }

    public function getNamespace(): string {
        return $this->namespace;
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlElementWrapper');

        $this->cannotBeCombinedWith(
            $ref,
            'XmlElementWrapper',
            'Xml',
            [XmlStereoType::class],
            [XmlElement::class]
        );

        $this->mustContainAnyOf(
            $ref,
            'XmlElementWrapper',
            [XmlElement::class]
        );

        $this->refOwner = $ref;
    }
}