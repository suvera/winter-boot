<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use DateTime;
use dev\winterframework\exception\NullPointerException;
use dev\winterframework\io\ObjectMapper;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\ParameterType;
use ReflectionProperty;
use Throwable;
use TypeError;

trait ObjectPropertySetter {

    protected static function doSetObjectProperty(
        RefProperty|ReflectionProperty $property,
        object $obj,
        mixed $value,
        int $source = ObjectMapper::SOURCE_ARRAY
    ): void {
        $propertyType = ParameterType::fromType($property->getType());
        $property->setAccessible(true);

        if (is_null($value)) {
            if (!$propertyType->allowsNull()) {
                throw new NullPointerException('Property "'
                    . ReflectionUtil::getFqName($property) . '" cannot be nullable at class '
                    . ReflectionUtil::getFqName($property->getDeclaringClass())
                );
            }
            $property->setValue($obj, $value);
            return;
        }

        /**
         * Handle Integer
         */
        if (is_int($value)) {
            if ($propertyType->hasType("int")) {
                $property->setValue($obj, $value);
                return;
            }
            self::throwTypeError($property, 'INTEGER');
        }

        /**
         * Handle Float
         */
        if (is_float($value)) {
            if ($propertyType->hasType("float")) {
                $property->setValue($obj, $value);
                return;
            }
            self::throwTypeError($property, 'FLOAT');
        }

        /**
         * Handle Boolean
         */
        if (is_bool($value)) {
            if ($propertyType->hasType("bool")) {
                $property->setValue($obj, $value);
                return;
            }
            self::throwTypeError($property, 'BOOLEAN');
        }


        /**
         * Handle String
         */
        if (is_string($value)) {

            $valLower = strtolower($value);

            if ($propertyType->hasType("string")) {
                $property->setValue($obj, $value);
                return;
            } else if ($propertyType->hasType("bool") && ($valLower === 'true' || $valLower === 'false')) {
                $property->setValue($obj, ($valLower === 'true'));
                return;
            } else if (is_numeric($value)) {
                $value = $value + 0;
                if (is_float($value) && $propertyType->hasType("float")) {
                    $property->setValue($obj, $value);
                    return;
                } else if ($propertyType->hasType("int")) {
                    $property->setValue($obj, $value);
                    return;
                }
            } else if ($propertyType->isDateTimeType()) {
                try {
                    $property->setValue($obj, new DateTime($value));
                    return;
                } /** @noinspection PhpUnusedLocalVariableInspection */
                catch (Throwable $e) {
                    // do nothing
                }
            }
            self::throwTypeError($property, 'STRING');
        }

        /**
         * Handle Array
         */
        if (is_array($value)) {
            if ($propertyType->hasType("array")) {
                $property->setValue($obj, $value);
                return;
            }

            $classTypes = $propertyType->getClassTypes();
            foreach ($classTypes as $classType) {
                $cls = RefKlass::getInstance($classType);
                if ($cls->isInstantiable()) {
                    if ($source == ObjectMapper::SOURCE_JSON || $source == ObjectMapper::SOURCE_ARRAY) {
                        $property->setValue($obj, ObjectCreator::createObject($classType, $value));
                        return;
                    }
                }
            }
            self::throwTypeError($property, 'ARRAY');
        }

        /**
         * Handle Object
         */
        if (is_object($value)) {
            if ($propertyType->hasType("object")) {
                $property->setValue($obj, $value);
                return;
            }

            $classTypes = $propertyType->getClassTypes();
            foreach ($classTypes as $classType) {
                if ($value instanceof $classType) {
                    $property->setValue($obj, $value);
                    return;
                }
            }
            self::throwTypeError($property, 'OBJECT');
        }


        /**
         * Handle Resource
         */
        if (is_resource($value)) {
            if ($propertyType->hasType("mixed")) {
                $property->setValue($obj, $value);
                return;
            }
            self::throwTypeError($property, 'RESOURCE');
        }

        // is_callable() - ignored
        self::throwTypeError($property);
    }

    private static function throwTypeError(
        RefProperty|ReflectionProperty $property,
        string $type = ''
    ): void {
        throw new TypeError('Property "'
            . ReflectionUtil::getFqName($property)
            . '" cannot be assigned to ' . $type . ' value at class '
            . ReflectionUtil::getFqName($property->getDeclaringClass())
        );
    }

}