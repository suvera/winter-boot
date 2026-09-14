<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class RestController implements StereoType {
    use StereoTypeValidations;

    private RefKlass $refOwner;

    public function __construct(
        public string $name = ''
    ) {
    }

    public function getRefOwner(): RefKlass {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);
        $this->cannotBeAbstractClass($ref, 'RestController');
        $this->cannotBeCombinedWith(
            $ref,
            'RestController',
            'Bean',
            [
                Service::class,
                Component::class,
                Configuration::class,
                Module::class,
                RestController::class,
            ]
        );

        $this->refOwner = $ref;
    }

}