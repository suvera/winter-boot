<?php

declare(strict_types=1);

namespace dev\winterframework\reflection\ref;


use dev\winterframework\exception\WinterException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use ReflectionAttribute;

/**
 * @method string __toString()
 * @method string getName()
 * @method mixed getValue(object $object = null)
 * @method mixed setValue(mixed $objectOrValue, mixed $value = null)
 * @method bool isInitialized(object $object = null)
 * @method bool isPublic()
 * @method bool isPrivate()
 * @method bool isProtected()
 * @method bool isStatic()
 * @method bool isDefault()
 * @method bool isPromoted()
 * @method int getModifiers()
 * @method ReflectionClass getDeclaringClass()
 * @method string getDocComment()
 * @method void setAccessible(bool $accessible)
 * @method ReflectionUnionType|ReflectionNamedType getType()
 * @method bool hasType()
 * @method bool hasDefaultValue()
 * @method mixed getDefaultValue()
 * @method ReflectionAttribute[] getAttributes(string $name = null, int $flags = 0)
 */
class RefProperty extends ReflectionAbstract {

    public static function getInstance(ReflectionProperty $value): self {
        $obj = new self();

        $obj->_data['class'] = $value->getDeclaringClass()->getName();
        $obj->_data['property'] = $value->getName();
        $obj->delegate = $value;

        return $obj;
    }

    protected function loadDelegate(): ReflectionProperty {
        try {
            return ReflectionRegistry::getClass($this->_data['class'])->getProperty($this->_data['property']);
        } catch (ReflectionException $e) {
            throw new WinterException('Could not load/find class property '
                . $this->_data['class']
                . '::' . $this->_data['property'], 0, $e
            );
        }
    }


}