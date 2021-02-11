<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class WinterBootApplication implements StereoType {
    public string $name = '';

    public function __construct(
        public array $configDirectory = [],
        /** array of records in format [NamespacePrefix, BaseDirectory] */
        public array $scanNamespaces = [],
        public bool $autoload = false,
        public array $scanExcludeNamespaces = [],
        public ?string $profile = null,
        /* Eagerly create Beans/Objects on start-up, useful but slowdown the application */
        public bool $eager = false
    ) {
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);
    }

}