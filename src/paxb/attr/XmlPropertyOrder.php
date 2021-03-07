<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class XmlPropertyOrder implements XmlStereoType {
    use StereoTypeValidations;

    private RefKlass $refOwner;

    public function __construct(
        protected array $order = [],
        protected bool $ignoreUnknown = true
    ) {
    }
    
    public function isIgnoreUnknown(): bool {
        return $this->ignoreUnknown;
    }

    public function getOrder(): array {
        return $this->order;
    }

    public function getRefOwner(): RefKlass {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $this->refOwner = $ref;
    }
}