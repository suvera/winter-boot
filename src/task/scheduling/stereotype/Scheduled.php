<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\task\scheduling\stereotype;

use Attribute;
use dev\winterframework\exception\AnnotationException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_METHOD)]
class Scheduled implements StereoType {
    use StereoTypeValidations;

    public function __construct(
        public int $fixedDelay = 0,
        public string $fixedDelayString = '',
        public int $fixedRate = 0,
        public string $fixedRateString = '',
        public int $initialDelay = 0,
        public string $initialDelayString = '',
    ) {
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        $stereoName = 'Scheduled';
        
        if (!extension_loaded('swoole')) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '] requires *swoole* extension in PHP runtime "
                . ReflectionUtil::getFqName($ref));
        }

        $this->cannotBeFinalMethod($ref, $stereoName);
        $this->cannotBeConstructor($ref, $stereoName);
        $this->cannotBeAbstractMethod($ref, $stereoName);
        $this->mustBePublicMethod($ref, $stereoName);
        $this->mustHaveZeroRequiredArgument($ref, $stereoName);
        $this->cannotHaveReturn($ref, $stereoName);
    }

}