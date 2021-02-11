<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\cli;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class Command implements StereoType {
    public string $name;
    public string $description;
    public string $help;

    public function __construct(
        string $name,
        string $description = '',
        string $help = ''
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->help = $help;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);
    }
}