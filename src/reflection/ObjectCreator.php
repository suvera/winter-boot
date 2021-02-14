<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\stereotype\JsonProperty;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

class ObjectCreator {

    public static function createObject(string $class, string|array $props): object {
        try {
            $ref = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new WinterException('Could not find class ' . $class, 0, $e);
        }

        try {
            if (is_string($props)) {
                $obj = $ref->newInstance($props);
                return $obj;
            } else {
                $obj = $ref->newInstance();
            }
        } catch (ReflectionException $e) {
            throw new WinterException('Could not create object of class ' . $class, 0, $e);
        }

        foreach ($ref->getProperties() as $refProp) {
            $attrs = $refProp->getAttributes(JsonProperty::class);
            $extName = $refProp->getName();

            if (count($attrs) > 0) {
                /** @var JsonProperty $attr */
                try {
                    $attr = $attrs[0]->newInstance();
                    $extName = $attr->name;
                } catch (Throwable $e) {
                    throw new InvalidSyntaxException('Invalid JsonProperty on property '
                        . $refProp->getName() . ', for class ' . $class, 0, $e
                    );
                }
            }

            if ($props[$extName]) {
                /** @var ReflectionNamedType|ReflectionUnionType $type */
                $type = $refProp->getType();
                if ($type != null && !($type instanceof ReflectionUnionType)) {
                    if ($type->isBuiltin()) {
                        $refProp->setValue($obj, $props[$extName]);
                    } else if (is_array($props[$extName])) {
                        $refProp->setValue($obj, self::createObject($type->getName(), $props[$extName]));
                    }
                } else {
                    $refProp->setValue($obj, $props[$extName]);
                }

            }
        }

        return $obj;
    }
}