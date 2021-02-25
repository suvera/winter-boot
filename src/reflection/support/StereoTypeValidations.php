<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use TypeError;

trait StereoTypeValidations {

    protected function validateAopMethod(RefMethod $ref, string $stereoName): void {
        $this->cannotBeConstructor($ref, $stereoName);

        $this->cannotBeAbstractMethod($ref, $stereoName);

        $this->cannotBePrivateMethod($ref, $stereoName);

        $this->cannotBeStaticMethod($ref, $stereoName);

        $this->cannotBeFinalMethod($ref, $stereoName);
    }

    protected function cannotBeConstructor(RefMethod $ref, string $stereoName): void {
        if ($ref->isConstructor() || $ref->isDestructor()) {
            throw new TypeError("#[$$stereoName] Annotation is not allowed on Constructor/Destructor"
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeAbstractMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isAbstract()) {
            throw new TypeError("#[$$stereoName] Annotation is not allowed on Abstract method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeFinalMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isFinal()) {
            throw new TypeError("#[$$stereoName] Annotation is not allowed on Final method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBePrivateMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isPrivate()) {
            throw new TypeError("#[$$stereoName] Annotation is not allowed on Private Method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeStaticMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isStatic()) {
            throw new TypeError("#[$$stereoName] Annotation is not allowed on Static Method "
                . ReflectionUtil::getFqName($ref));
        }
    }
}