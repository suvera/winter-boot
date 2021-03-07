<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class XmlTransient implements XmlStereoType {
    use StereoTypeValidations;

    private RefProperty $refOwner;

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlTransient');

        $this->cannotBeCombinedWith(
            $ref,
            'XmlTransient',
            'Xml',
            [XmlStereoType::class]
        );

        $this->refOwner = $ref;
    }

}