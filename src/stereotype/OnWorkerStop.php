<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\core\app\WorkerStopEvent;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class OnWorkerStop implements StereoType {
    use StereoTypeValidations;

    private RefKlass $refOwner;

    public function __construct() {
    }

    public function getRefOwner(): RefKlass {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $this->mustImplementOneOf($ref, 'OnWorkerStop', WorkerStopEvent::class);

        $this->mustHaveAttributeOneOf(
            $ref,
            'OnWorkerStop',
            Component::class,
            Service::class,
            Configuration::class
        );

        $this->refOwner = $ref;
    }

}