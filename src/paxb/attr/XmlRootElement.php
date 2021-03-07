<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class XmlRootElement implements XmlStereoType {
    use StereoTypeValidations;

    private RefKlass $refOwner;

    public function __construct(
        protected string $name = ''
    ) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function getRefOwner(): RefKlass {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $this->refOwner = $ref;

        if (empty($this->name)) {
            $this->name = $ref->getShortName();
        }
    }

}