<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\paxb\XmlObjectMapper;
use dev\winterframework\reflection\ref\ReflectionRegistry;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\stereotype\JsonProperty;
use ReflectionClass;
use ReflectionException;
use Throwable;

class ObjectCreator {
    use ObjectPropertySetter;

    protected static function getClass(string|object $classOrObj): ReflectionClass {
        $class = is_object($classOrObj) ? $classOrObj::class : $classOrObj;
        return ReflectionRegistry::getClass($class);
    }

    public static function createObject(string $class, string|array $props): object {
        $ref = self::getClass($class);

        try {
            if (is_scalar($props)) {
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
                $gotValue = false;
                /** @var JsonProperty $attr */
                try {
                    $attr = $attrs[0]->newInstance();
                    $attr->init(RefProperty::getInstance($refProp));
                    if (is_array($attr->name)) {
                        foreach ($attr->name as $alias) {
                            $extName = $alias;
                            if (array_key_exists($extName, $props)) {
                                if (isset($props[$extName]) || $attr->isNillable()) {
                                    $gotValue = true;
                                    break;
                                }
                            }
                        }
                    } else {
                        $extName = $attr->name;
                    }

                    if (!$gotValue && $attr->isRequired() && !isset($props[$extName])) {
                        throw new InvalidSyntaxException('Property "' . $refProp->getName()
                            . '" is Required, at class ' . $ref->getName()
                        );
                    }

                    if (isset($props[$extName])) {

                        if ($attr->isList()) {
                            if (!is_array($props[$extName])) {
                                throw new InvalidSyntaxException('Property "' . $refProp->getName()
                                    . '" is defined as Array, but non-array value seen, at class ' . $ref->getName()
                                );
                            }
                            $list = [];
                            foreach ($props[$extName] as $value) {
                                $list[] = ObjectCreator::createObject($attr->getListClass(), $value);
                            }
                            $props[$extName] = $list;
                        } else if ($attr->isObject()) {
                            $props[$extName] = ObjectCreator::createObject($attr->getObjectClass(), $props[$extName]);
                        }
                    }

                } catch (InvalidSyntaxException $ex) {
                    throw $ex;
                } catch (Throwable $e) {
                    throw new InvalidSyntaxException('Invalid JsonProperty on property '
                        . $refProp->getName() . ', for class ' . $ref->getName(), 0, $e
                    );
                }
            }

            if (isset($props[$extName])) {
                self::doSetObjectProperty($refProp, $obj, $props[$extName]);
            }
        }

        return $obj;
    }

    public static function createObjectXml(string $className, string $xml): object {
        $mapper = new XmlObjectMapper();

        return $mapper->readValue($xml, $className);
    }
}