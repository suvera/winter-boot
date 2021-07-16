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
class XmlValue implements XmlStereoType {
    use XmlStereoTypeTrait;
    use StereoTypeValidations;

    const FILTER_TRIM = 1;
    const FILTER_LENGTH = 2;
    const FILTER_UPPERCASE = 3;
    const FILTER_LOWERCASE = 4;

    private RefProperty $refOwner;

    public function __construct(
        protected string $valueAdapter = '',
        protected array $filters = [],
        protected bool $cData = false
    ) {
        if ($this->valueAdapter) {
            $this->validateValueAdapter($this->valueAdapter, 'XmlValue');
        }
    }

    public function getFilters(): array {
        return $this->filters;
    }

    public function isCData(): bool {
        return $this->cData;
    }

    public function getValueAdapter(): string {
        return $this->valueAdapter;
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlValue');
        $this->assertParameterOfType(
            $ref->getDelegate(),
            'XmlValue',
            ['string', 'int', 'float', 'bool', DateTimeInterface::class, DateTime::class]
        );

        $this->cannotBeCombinedWith(
            $ref,
            'XmlValue',
            'Xml',
            [XmlStereoType::class]
        );

        $this->refOwner = $ref;
    }

}