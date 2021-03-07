<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\ReflectionAbstract;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;

class ReflectionUtil {

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
}