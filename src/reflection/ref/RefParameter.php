<?php

declare(strict_types=1);

namespace dev\winterframework\reflection\ref;

use dev\winterframework\exception\WinterException;
use ReflectionException;
use ReflectionParameter;

/**
 * @method string __toString()
 * @method mixed getName()
 * @method mixed isPassedByReference()
 * @method mixed canBePassedByValue()
 * @method mixed getDeclaringFunction()
 * @method mixed getDeclaringClass()
 * @method mixed getClass()
 * @method mixed hasType()
 * @method mixed getType()
 * @method mixed isArray()
 * @method mixed isCallable()
 * @method mixed allowsNull()
 * @method mixed getPosition()
 * @method mixed isOptional()
 * @method mixed isDefaultValueAvailable()
 * @method mixed getDefaultValue()
 * @method mixed isDefaultValueConstant()
 * @method mixed getDefaultValueConstantName()
 * @method mixed isVariadic()
 * @method bool isPromoted()
 * @method array getAttributes(string $name = null, int $flags = 0)
 */
class RefParameter extends ReflectionAbstract {

    public static function getInstance(ReflectionParameter|self $value): self {
        if ($value instanceof self) {
            return $value;
        }
        $obj = new self();

        $obj->_data['class'] = $value->getDeclaringClass()->getName();
        $obj->_data['method'] = $value->getDeclaringFunction()->getName();
        $obj->_data['parameter'] = $value->getName();
        $obj->delegate = $value;

        return $obj;
    }

    protected function loadDelegate(): ReflectionParameter {
        try {
            $params = ReflectionRegistry::getClass($this->_data['class'])
                ->getMethod($this->_data['method'])
                ->getParameters();

            foreach ($params as $param) {
                if ($param->getName() == $this->_data['parameter']) {
                    return $param;
                }
            }

            throw new ReflectionException('no parameter found');

        } catch (ReflectionException $e) {
            throw new WinterException('Could not load/find method parameter '
                . $this->_data['class']
                . '::' . $this->_data['method']
                . '(' . $this->_data['parameter'] . ')'
                , 0, $e
            );
        }
    }


}