<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\AnnotationException;
use dev\winterframework\exception\BeansException;
use dev\winterframework\exception\MissingExtensionException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\ReflectionAbstract;
use dev\winterframework\reflection\ref\ReflectionRegistry;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Value;
use dev\winterframework\type\TypeCast;
use dev\winterframework\util\log\Wlf4p;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;

class ReflectionUtil {
    use Wlf4p;

    /**
     * get Fully Qualified Method Name
     *
     * @param ReflectionMethod $method
     * @return string
     */
    public static function getFqmn(ReflectionMethod $method): string {
        $name = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        return "$name::$methodName()";
    }

    /**
     * get Fully Qualified Property Name
     *
     * @param ReflectionProperty $prop
     * @return string
     */
    public static function getFqpn(ReflectionProperty $prop): string {
        $name = $prop->getDeclaringClass()->getName();
        $methodName = $prop->getName();
        return "$name\::$methodName";
    }

    /**
     * get Fully Qualified Name
     *
     * @param object $ref
     * @return string
     */
    public static function getFqName(object $ref): string {
        if ($ref instanceof ReflectionMethod) {
            return self::getFqmn($ref);
        } else if ($ref instanceof ReflectionClass || $ref instanceof ReflectionObject) {
            return $ref->getName();
        } else if ($ref instanceof ReflectionProperty) {
            return self::getFqpn($ref);
        } else if ($ref instanceof ReflectionFunction) {
            return $ref->getName();
        } else if ($ref instanceof ReflectionParameter) {
            return $ref->getName();
        } else if ($ref instanceof ReflectionNamedType) {
            return $ref->getName();
        } else if ($ref instanceof ClassResource) {
            return self::getFqName($ref->getClass());
        } else if ($ref instanceof MethodResource) {
            return self::getFqName($ref->getMethod());
        } else if ($ref instanceof VariableResource) {
            return self::getFqName($ref->getVariable());
        } else if ($ref instanceof ReflectionAbstract) {
            return self::getFqName($ref->getDelegate());
        }

        return "Class:" . get_class($ref);
    }

    public static function getParamType(ReflectionParameter $param): string {
        if (!$param->hasType()) {
            return '';
        }

        /** @var ReflectionNamedType|ReflectionUnionType $type */
        $type = $param->getType();
        return self::getTypeString($type);
    }

    public static function getReturnType(ReflectionMethod $method): string {
        if (!$method->hasReturnType()) {
            return '';
        }

        /** @var ReflectionNamedType|ReflectionUnionType $type */
        $type = $method->getReturnType();
        return self::getTypeString($type);
    }

    public static function getTypeString(ReflectionNamedType|ReflectionUnionType $type): string {
        if ($type == null) {
            return '';
        }

        if ($type instanceof ReflectionUnionType) {
            $mixType = '';
            foreach ($type->getTypes() as $subType) {
                if (!empty($mixType)) {
                    $mixType .= '|';
                }
                $mixType .= $subType->getName();
            }

            return $mixType;
        }

        return $type->getName();
    }

    /**
     * @param string $className
     * @return string
     * @throws
     */
    public static function generateStub(string $className): string {
        $cls = new ReflectionClass($className);

        $stubs = [];
        foreach ($cls->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }
            $m = '@method ';

            if (!$method->hasReturnType()) {
                $retType = 'mixed';
            } else {
                /** @var ReflectionNamedType $type */
                $type = $method->getReturnType();
                $retType = $type->getName();
            }

            $m .= $retType . ' ' . $method->getShortName() . '(';

            $typeName = '';
            foreach ($method->getParameters() as $i => $p) {
                if (!$p->hasType()) {
                    $typeName = 'mixed';
                } else {
                    /** @var ReflectionNamedType|ReflectionUnionType $type */
                    $type = $p->getType();
                    if ($type instanceof ReflectionUnionType) {
                        foreach ($type->getTypes() as $uType) {
                            /** @var ReflectionNamedType $uType */
                            if (!empty($typeName)) {
                                $typeName .= '|';
                            }
                            $typeName .= $uType->getName();
                        }
                    } else {
                        $type = $p->getType();
                        $typeName = $type->getName();
                    }
                }
                if ($i > 0) {
                    $m .= ', ';
                }

                $m .= $typeName . ' $' . $p->getName();

                if ($p->isDefaultValueAvailable()) {
                    $m .= ' = ';
                    if ($p->isDefaultValueConstant()) {
                        $m .= $p->getDefaultValueConstantName();
                    } else {
                        $m .= json_encode($p->getDefaultValue());
                    }
                }
            }

            $m .= ')';
            $stubs[] = $m;
        }

        return ' * ' . implode("\n * ", $stubs) . "\n";
    }

    public static function createAttribute(ReflectionAttribute $attribute, object $owner): object {
        try {
            $attr = $attribute->newInstance();
            $attr->init($owner);
            return $attr;
        } catch (Throwable $e) {
            throw new AnnotationException('Could not build Annotation object '
                . $attribute->getName(), 0, $e);
        }
    }

    public static function classToPropertiesTemplate(string $clsName, array &$seen = []): array {
        $ref = RefKlass::getInstance($clsName);
        $props = $ref->getProperties(~ReflectionProperty::IS_STATIC);
        $arr = [];
        foreach ($props as $prop) {
            $type = ParameterType::fromType($prop->getType());
            if ($type->isDateTimeType()
                || $type->isBuiltin()
                || $type->isUnionType()
                || $type->isNoType()
                || $type->isVoidType()
                || $type->isMixedType()
                || isset($seen[$prop->getName()])
            ) {
                $arr[$prop->getName()] = $type->getName();
            } else {
                $seen[$prop->getName()] = true;
                $arr[$prop->getName()] = self::classToPropertiesTemplate($type->getName(), $seen);
            }
        }
        return $arr;
    }

    public static function phpExtension(string $extName): bool {
        return extension_loaded($extName);
    }

    public static function assertPhpExtension(string $extName): void {
        if (!extension_loaded($extName)) {
            throw new MissingExtensionException("missing *$extName* extension in PHP runtime");
        }
    }

    public static function assertPhpAnyExtension(array $extNames): void {
        foreach ($extNames as $extName) {
            if (extension_loaded($extName)) {
                return;
            }
        }
        throw new MissingExtensionException("Require one of [" . implode(', ', $extNames)
            . "] extension in PHP runtime");
    }

    public static function createAutoWiredObject(
        ApplicationContext $ctx,
        RefKlass|ReflectionClass $cls,
        mixed  ...$args
    ): object {
        try {
            $object = $cls->newInstanceWithoutConstructor();
            $cls->getConstructor()?->invoke($object, ...$args);
        } catch (Throwable $e) {
            throw new BeansException('Could not create object of class ' . $cls->getName()
                . ' due to ' . $e->getMessage(), 0, $e);
        }

        self::performAutoWiredProperties($ctx, $cls, $object);
        return $object;
    }

    public static function performAutoWiredProperties(
        ApplicationContext $ctx,
        RefKlass|ReflectionClass $cls,
        object $object
    ): void {
        $properties = $cls->getProperties();
        foreach ($properties as $property) {
            $attributes = $property->getAttributes(Autowired::class);
            if ($attributes) {
                foreach ($attributes as $attribute) {
                    /** @var Autowired $autoWired */
                    $autoWired = $attribute->newInstance();
                    $autoWired->init(RefProperty::getInstance($property));
                    self::performAutoWired($ctx, $autoWired, $object);
                }
            }

            $autoValues = $property->getAttributes(Value::class);
            if ($autoValues) {
                foreach ($autoValues as $attribute) {
                    /** @var Value $autoValue */
                    $autoValue = $attribute->newInstance();
                    $autoValue->init(RefProperty::getInstance($property));
                    self::performAutoValue($ctx, $autoValue, $object);
                }
            }
        }
    }

    public static function performAutoValue(
        ApplicationContext $ctx,
        Value $autoValue,
        object $bean
    ): void {
        $ref = $autoValue->getRefOwner();
        $ref->setAccessible(true);

        $ymlName = substr($autoValue->name, 2, -1);
        $val = null;

        try {
            $val = $ctx->getProperty($ymlName);
        } catch (Throwable $e) {
            self::logDebug($e->getMessage());
        }

        if (is_null($val)) {
            if (isset($autoValue->defaultValue)) {

                try {
                    $val = TypeCast::parseValue($autoValue->getTargetType(), $autoValue->defaultValue);
                } catch (Throwable $e) {
                    throw new WinterException('Invalid Type defined for config property #[Value] "'
                        . $autoValue->name
                        . '" (' . $autoValue->getTargetType() . ' - '
                        . $e->getMessage() . '), so, Could not instantiate object for class '
                        . get_class($bean), 0, $e
                    );
                }

            } else if (!$autoValue->isNullable()) {

                throw new WinterException('Could not find config property #[Value] "'
                    . $autoValue->name
                    . '", so, Could not instantiate object for class '
                    . get_class($bean)
                );

            } else {
                return;
            }
        }

        if ($autoValue->isTargetStatic()) {
            $ref->setValue($val);
        } else {
            $ref->setValue($bean, $val);
        }
    }

    public static function performAutoWired(
        ApplicationContext $ctx,
        Autowired $autoWired,
        object $object
    ): void {
        $ref = $autoWired->getRefOwner();
        $ref->setAccessible(true);

        try {
            $val = $ref->getValue($object);
        } catch (Throwable $e) {
            self::logDebug($e->getMessage());
            $val = null;
        }

        if (isset($val)) {
            // Variable already set
            return;
        }

        if ($autoWired->name) {
            $childBean = $ctx->beanByNameClass($autoWired->name, $autoWired->getTargetType());
        } else {
            $childBean = $ctx->beanByClass($autoWired->getTargetType());
        }

        if ($autoWired->isTargetStatic()) {
            $ref->setValue($childBean);
        } else {
            $ref->setValue($object, $childBean);
        }
    }

    /**
     * @throws
     */
    public static function setProperty(object $object, string $propName, mixed $value): void {
        $ref = ReflectionRegistry::getClass($object::class);
        $prop = $ref->getProperty($propName);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}