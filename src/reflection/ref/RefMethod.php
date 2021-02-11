<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\ref;

use dev\winterframework\exception\WinterException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * @method string __toString()
 * @method mixed isPublic()
 * @method mixed isPrivate()
 * @method mixed isProtected()
 * @method mixed isAbstract()
 * @method mixed isFinal()
 * @method mixed isStatic()
 * @method mixed isConstructor()
 * @method mixed isDestructor()
 * @method mixed getClosure(object $object = null)
 * @method mixed getModifiers()
 * @method mixed invoke(object $object, mixed $args)
 * @method mixed invokeArgs(object $object, array $args)
 * @method ReflectionClass getDeclaringClass()
 * @method mixed getPrototype()
 * @method mixed setAccessible(bool $accessible)
 * @method mixed inNamespace()
 * @method mixed isClosure()
 * @method mixed isDeprecated()
 * @method mixed isInternal()
 * @method mixed isUserDefined()
 * @method mixed isGenerator()
 * @method mixed isVariadic()
 * @method mixed getClosureThis()
 * @method mixed getClosureScopeClass()
 * @method mixed getDocComment()
 * @method mixed getEndLine()
 * @method mixed getExtension()
 * @method mixed getExtensionName()
 * @method mixed getFileName()
 * @method mixed getName()
 * @method mixed getNamespaceName()
 * @method mixed getNumberOfParameters()
 * @method mixed getNumberOfRequiredParameters()
 * @method mixed getParameters()
 * @method mixed getShortName()
 * @method mixed getStartLine()
 * @method mixed getStaticVariables()
 * @method mixed returnsReference()
 * @method mixed hasReturnType()
 * @method mixed getReturnType()
 * @method array getAttributes(string $name = null, int $flags = 0)
 */
class RefMethod extends ReflectionAbstract {

    public static function getInstance(ReflectionMethod $value): self {
        $obj = new self();

        $obj->_data['class'] = $value->getDeclaringClass()->getName();
        $obj->_data['method'] = $value->getShortName();
        $obj->delegate = $value;
        return $obj;
    }

    protected function loadDelegate(): ReflectionMethod {
        try {
            return ReflectionRegistry::getClass($this->_data['class'])->getMethod($this->_data['method']);
        } catch (ReflectionException $e) {
            throw new WinterException('Could not load/find class method '
                . $this->_data['class']
                . '::' . $this->_data['method']
                , 0, $e
            );
        }
    }


}