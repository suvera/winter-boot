<?php
declare(strict_types=1);


namespace dev\winterframework\stereotype\ppa;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class Table implements StereoType {
    use StereoTypeValidations;

    public function __construct(
        protected string $name = ''
    ) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::objectOf($ref, RefKlass::class);

        if (!$this->name) {
            $this->name = $ref->getName();
        }

    }

}