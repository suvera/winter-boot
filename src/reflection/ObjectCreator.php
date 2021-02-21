<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ref\ReflectionRegistry;
use dev\winterframework\stereotype\JsonProperty;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

class ObjectCreator {

    protected static function getClass(string|object $classOrObj): ReflectionClass {
        $class = is_object($classOrObj) ? $classOrObj::class : $classOrObj;
        return ReflectionRegistry::getClass($class);
    }

    public static function createObject(string $class, string|array $props): object {
        $ref = self::getClass($class);

        try {
            if (is_string($props)) {
                return $ref->newInstance($props);
            } else {
                $obj = $ref->newInstance();
            }
        } catch (ReflectionException $e) {
            throw new WinterException('Could not create object of class ' . $class, 0, $e);
        }
        return self::mapObject($obj, $props, $ref);
    }

    public static function mapObject(object $obj, string|array $props, ReflectionClass $ref = null): object {
        if (!$ref) {
            $ref = self::getClass($obj);
        }
        foreach ($ref->getProperties() as $refProp) {
            $attrs = $refProp->getAttributes(JsonProperty::class);
            $extName = $refProp->getName();

            if (count($attrs) > 0) {
                /** @var JsonProperty $attr */
                try {
                    $attr = $attrs[0]->newInstance();
                    if (is_array($attr->name)) {
                        foreach ($attr->name as $alias) {
                            $extName = $alias;
                            if (isset($props[$alias])) {
                                break;
                            }
                        }
                    } else {
                        $extName = $attr->name;
                    }

                } catch (Throwable $e) {
                    throw new InvalidSyntaxException('Invalid JsonProperty on property '
                        . $refProp->getName() . ', for class ' . $ref->getName(), 0, $e
                    );
                }
            }

            if (isset($props[$extName])) {
                /** @var ReflectionNamedType|ReflectionUnionType $type */
                $type = $refProp->getType();
                $refProp->setAccessible(true);
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