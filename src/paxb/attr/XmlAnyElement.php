<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class XmlAnyElement implements XmlStereoType {
    use StereoTypeValidations;

    private RefProperty $refOwner;

    public function __construct(
        protected bool $lax = false
    ) {
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlAnyElement');
        $this->assertParameterIsArray($ref->getDelegate(), 'XmlAnyAttribute');

        $this->cannotBeCombinedWith(
            $ref,
            'XmlAnyElement',
            'Xml',
            [XmlStereoType::class]
        );

        $this->refOwner = $ref;
    }

}