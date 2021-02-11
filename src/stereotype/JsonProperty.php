<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\type\TypeAssert;

class JsonProperty implements StereoType {
    public function __construct(
        public string $name = ''
    ) {
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);
    }
}