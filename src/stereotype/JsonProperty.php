<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class JsonProperty implements StereoType {
    public function __construct(
        public array|string $name = '',
        protected bool $required = false,
        protected bool $nillable = false
    ) {
    }

    public function isRequired(): bool {
        return $this->required;
    }

    public function isNillable(): bool {
        return $this->nillable;
    }

    public function getName(): array|string {
        return $this->name;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);
        if (empty($this->name)) {
            $this->name = $ref->getName();
        }
    }
}