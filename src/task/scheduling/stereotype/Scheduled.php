<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\task\scheduling\stereotype;

use Attribute;
use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\exception\AnnotationException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use ValueError;

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

    public function setFixedDelay(int $value): void {
        if ($value <= 0) {
            throw new ValueError("cannot be set non-positive value to fixedDelay parameter "
                . "of Annotation #[Scheduled]  ");
        }
        $this->fixedDelay = $value;
    }

    public function setFixedRate(int $value): void {
        if ($value <= 0) {
            throw new ValueError("cannot be set non-positive value to fixedRate parameter "
                . "of Annotation #[Scheduled]  ");
        }
        $this->fixedRate = $value;
    }

    public function setInitialDelay(int $value): void {
        if ($value <= 0) {
            throw new ValueError("cannot be set non-positive value to initialDelay parameter "
                . "of Annotation #[Scheduled]  ");
        }
        $this->initialDelay = $value;
    }

    public function setPropertyValues(PropertyContext $prop) {
        if ($this->fixedRateString) {
            $propName = substr($this->fixedRateString, 2, -1);
            $val = $prop->getInt($propName);
            $this->setFixedRate($val);
        }
        if ($this->fixedDelayString) {
            $propName = substr($this->fixedDelayString, 2, -1);
            $val = $prop->getInt($propName);
            $this->setFixedDelay($val);
        }
        if ($this->initialDelayString) {
            $propName = substr($this->initialDelayString, 2, -1);
            $val = $prop->getInt($propName);
            $this->setInitialDelay($val);
        }
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

        if ($this->fixedDelay <= 0 && !$this->fixedDelayString && $this->fixedRate <= 0 && !$this->fixedRateString) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '] requires one of the parameters set [fixedDelay, fixedDelayString, fixedRate, fixedRateString] "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedDelay > 0 && $this->fixedDelayString) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedDelay' and 'fixedDelayString' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedDelay > 0 && $this->fixedRate > 0) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedDelay' and 'fixedRate' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedDelay > 0 && $this->fixedRateString) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedDelay' and 'fixedRateString' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedRateString && $this->fixedRate > 0) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedRateString' and 'fixedRate' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedDelayString && $this->fixedRate > 0) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedDelayString' and 'fixedRate' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

        if ($this->fixedRateString && $this->fixedDelayString > 0) {
            throw new AnnotationException("Annotation #[' . $stereoName 
                . '], both parameters 'fixedRateString' and 'fixedDelayString' cannot be set at same time "
                . ReflectionUtil::getFqName($ref));
        }

    }

}