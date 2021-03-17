<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\exception\NullPointerException;
use dev\winterframework\io\ObjectMapper;
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

        try {
            $property->setValue($obj, $propertyType->castValue(
                $value,
                $source,
                $property->hasDefaultValue() ? $property->getDefaultValue() : null
            ));
        } catch (NullPointerException $ex) {
            throw new NullPointerException('Property "'
                . ReflectionUtil::getFqName($property) . '" cannot be nullable at class '
                . ReflectionUtil::getFqName($property->getDeclaringClass()),
                0, $ex
            );
        } catch (Throwable $e) {
            throw new TypeError($e->getMessage() . ', Property "'
                . ' at class '
                . ReflectionUtil::getFqName($property->getDeclaringClass()),
                0, $e
            );
        }
    }
}