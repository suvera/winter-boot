<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_METHOD)]
class PostConstruct implements StereoType {
    use StereoTypeValidations;

    private RefMethod $refOwner;

    public function __construct() {
    }

    public function getRefOwner(): RefMethod {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        $this->cannotHaveReturn($ref, 'PostConstruct');

        $this->mustBePublicMethod($ref, 'PostConstruct');
        $this->cannotBeAbstractMethod($ref, 'PostConstruct');
        $this->cannotBeConstructor($ref, 'PostConstruct');

        $this->refOwner = $ref;
    }
}