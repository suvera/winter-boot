<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
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
            throw new TypeError("#[$stereoName] Annotation is not allowed on Constructor/Destructor"
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeAbstractMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isAbstract()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Abstract method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeFinalMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isFinal()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Final method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBePrivateMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isPrivate()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Private Method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeProtectedMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isProtected()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Private Method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function mustBePublicMethod(RefMethod $ref, string $stereoName): void {
        if (!$ref->isPublic()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Private/Protected Method,"
                . " must be public. "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeStaticMethod(RefMethod $ref, string $stereoName): void {
        if ($ref->isStatic()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Static Method "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeStaticProperty(RefProperty $ref, string $stereoName): void {
        if ($ref->isStatic()) {
            throw new TypeError("#[$stereoName] Annotation is not allowed on Static Property "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotHaveReturn(RefMethod $ref, string $stereoName): void {
        $type = ParameterType::fromType($ref->getReturnType());
        if ($type->isUnionType() || !($type->isNoType() || $type->isVoidType())) {
            throw new TypeError("#[$stereoName] Attribute method cannot have return type at "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function mustHaveZeroRequiredArgument(RefMethod $ref, string $stereoName): void {
        if ($ref->getNumberOfParameters() > 0 && $ref->getNumberOfRequiredParameters() > 0) {
            throw new TypeError("#[$stereoName] Attribute method must not contain any "
                . "required arguments at "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function mustHaveSingleRequiredArgument(RefMethod $ref, string $stereoName): void {
        if ($ref->getNumberOfParameters() == 0 || $ref->getNumberOfRequiredParameters() > 1) {
            throw new TypeError("#[$stereoName] Attribute method must contain at-least "
                . "one argument and cannot have more than one required arguments "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function cannotBeCombinedWith(
        RefProperty|RefMethod|RefKlass $ref,
        string $stereoName,
        string $otherStereoCategory,
        array $classes,
        array $excludedClasses = []
    ): void {
        $attrs = $ref->getAttributes();
        $count = 0;
        foreach ($attrs as $attr) {
            $class = $attr->getName();
            foreach ($classes as $ofTheClass) {
                if (is_a($class, $ofTheClass, true) && !in_array($class, $excludedClasses)) {
                    $count++;
                }

                if ($count > 1) {
                    throw new TypeError("#[$stereoName] Annotation is not "
                        . "allowed on with Other '" . $otherStereoCategory . "' type of Attributes "
                        . ReflectionUtil::getFqName($ref));
                }
            }
        }
    }


    protected function mustContainAnyOf(
        RefProperty|RefMethod $ref,
        string $stereoName,
        array $classes
    ): void {

        $found = false;
        foreach ($classes as $class) {
            $attrs = $ref->getAttributes($class);
            if (count($attrs) > 0) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new TypeError("#[$stereoName] Annotation must need any of the attributes "
                . " '" . implode("', '", $classes) . "' at  "
                . ReflectionUtil::getFqName($ref));
        }
    }

    protected function assertParameterOfType(
        ReflectionParameter|ReflectionProperty $param,
        string $stereoName,
        string|array $typeName
    ): void {
        $type = $param->getType();
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                if ($subType->getName() === 'mixed') {
                    return;
                }

                if (is_string($typeName)) {
                    if ($subType->getName() === $typeName) {
                        return;
                    }
                } else {
                    foreach ($typeName as $typeVal) {
                        if ($subType->getName() === $typeVal) {
                            return;
                        }
                    }
                }
            }
        } else if ($type instanceof ReflectionNamedType) {
            if ($type->getName() === 'mixed') {
                return;
            }

            if (is_string($typeName)) {
                if ($type->getName() === $typeName) {
                    return;
                }
            } else {
                foreach ($typeName as $typeVal) {
                    if ($type->getName() === $typeVal) {
                        return;
                    }
                }
            }
        } else {
            // no type mentioned, can accept anything
            return;
        }

        if ($param instanceof ReflectionParameter) {
            throw new TypeError("#[$stereoName] Attribute method parameter "
                . '"' . $param->getName() . '"'
                . ' must be of type [' . json_encode($typeName) . '] at method '
                . ReflectionUtil::getFqName($param->getDeclaringFunction())
            );
        }
        throw new TypeError("#[$stereoName] Attribute define class property "
            . '"' . $param->getName() . '"'
            . ' must be of type [' . json_encode($typeName) . '] at class '
            . ReflectionUtil::getFqName($param->getDeclaringClass())
        );
    }

    protected function assertParameterIsString(
        ReflectionParameter|ReflectionProperty $param,
        string $stereoName
    ): void {
        $this->assertParameterOfType($param, $stereoName, 'string');
    }

    protected function assertParameterIsArray(
        ReflectionParameter|ReflectionProperty $param,
        string $stereoName
    ): void {
        $this->assertParameterOfType($param, $stereoName, 'array');
    }

    protected function mustImplementOneOf(
        RefKlass|ReflectionClass|ReflectionObject $cls,
        string $stereoName,
        string ...$interfaces
    ): void {

        foreach ($interfaces as $interface) {
            if ($cls->implementsInterface($interface)) {
                return;
            }
        }

        throw new TypeError("#[$stereoName] Attribute class "
            . '"' . $cls->getName() . '"'
            . ' must implement one of interfaces [' . json_encode($interfaces) . '] '
        );
    }

    protected function mustExtends(
        RefKlass|ReflectionClass|ReflectionObject $cls,
        string $stereoName,
        string $class
    ): void {

        if ($cls->isSubclassOf($class)) {
            return;
        }

        throw new TypeError("#[$stereoName] Attribute class "
            . '"' . $cls->getName() . '"'
            . ' must extend (be derived) from class ' . $class
        );
    }

    protected function mustHaveAttributeOneOf(
        RefKlass $ref,
        string $stereoName,
        string ...$attributes
    ): void {
        foreach ($attributes as $attribute) {
            if ($ref->getAttributes($attribute)) {
                return;
            }
        }

        throw new TypeError("#[$stereoName] Attribute class "
            . '"' . $ref->getName() . '"'
            . ' must have one of Attribute [' . json_encode($attributes)
        );
    }


    protected function cannotBeAbstractClass(RefKlass $ref, string $stereoName): void {
        if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
            throw new TypeError("#[$stereoName] Attribute class "
                . '"' . $ref->getName() . '"'
                . ' must not be abstract/interface/trait '
            );
        }
    }
}