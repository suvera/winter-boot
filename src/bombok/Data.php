<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\bombok;

use BadMethodCallException;
use InvalidArgumentException;
use ReflectionException;
use ReflectionObject;

trait Data {
    private ReflectionObject $reflection;

    public final function __call(string $name, mixed $arguments): mixed {
        $prefix = substr($name, 0, 3);
        if ($prefix === 'get' || $prefix === 'set') {

            if (!isset($this->reflection)) {
                $this->reflection = new ReflectionObject($this);
            }

            $field = substr($name, 3);
            $field[0] = strtolower($field[0]);

            try {
                $property = $this->reflection->getProperty($field);
            }
            /** @noinspection PhpUnusedLocalVariableInspection */
            catch (ReflectionException $e) {
                $property = null;
            }

            if (!$property) {
                throw new BadMethodCallException('Method ' . $name . ' does not exist on class ' . static::class);
            }

            if ($prefix === 'set') {
                if (!is_array($arguments) || count($arguments) != 1) {
                    throw new BadMethodCallException('Setter Method ' . $name . ' must have one argument '
                        . ', on class ' . static::class);
                }

                /** @var \ReflectionNamedType $type */
                $type = $property->getType();
                if ($type && !($type->allowsNull() && is_null($arguments[0]))) {
                    $expected = $type->getName();
                    $actual = gettype($arguments[0]);

                    if ($actual === 'object') {
                        $actual = get_class($arguments[0]);
                    } else if ($actual === 'integer') {
                        $actual = 'int';
                    }

                    if ($actual !== $expected) {
                        throw new InvalidArgumentException('Data type passed to Setter method ' . $name
                            . ' must have be of type ' . $expected . ' but got ' . $actual
                            . ', on class ' . static::class);
                    }
                }

                $this->$field = $arguments[0];
                return null;
            } else {
                if (is_array($arguments) && count($arguments) > 0) {
                    throw new BadMethodCallException('Getter method ' . $name . ' must not value any arguments '
                        . ', on class ' . static::class);
                }
                return $this->$field;
            }
        }

        throw new BadMethodCallException('Method ' . $name . ' does not exist on class ' . static::class);
    }
}
