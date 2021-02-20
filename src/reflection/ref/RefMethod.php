<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\ref;

use dev\winterframework\exception\WinterException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * @method string __toString()
 * @method bool isPublic()
 * @method bool isPrivate()
 * @method bool isProtected()
 * @method bool isAbstract()
 * @method bool isFinal()
 * @method bool isStatic()
 * @method bool isConstructor()
 * @method bool isDestructor()
 * @method mixed getClosure(object $object = null)
 * @method mixed getModifiers()
 * @method mixed invoke(object $object, mixed $args)
 * @method mixed invokeArgs(object $object, array $args)
 * @method ReflectionClass getDeclaringClass()
 * @method mixed getPrototype()
 * @method mixed setAccessible(bool $accessible)
 * @method bool inNamespace()
 * @method bool isClosure()
 * @method bool isDeprecated()
 * @method bool isInternal()
 * @method bool isUserDefined()
 * @method bool isGenerator()
 * @method bool isVariadic()
 * @method mixed getClosureThis()
 * @method mixed getClosureScopeClass()
 * @method mixed getDocComment()
 * @method mixed getEndLine()
 * @method mixed getExtension()
 * @method mixed getExtensionName()
 * @method string getFileName()
 * @method string getName()
 * @method string getNamespaceName()
 * @method int getNumberOfParameters()
 * @method int getNumberOfRequiredParameters()
 * @method ReflectionParameter[] getParameters()
 * @method string getShortName()
 * @method mixed getStartLine()
 * @method mixed getStaticVariables()
 * @method mixed returnsReference()
 * @method bool hasReturnType()
 * @method ReflectionNamedType|ReflectionUnionType|null getReturnType()
 * @method ReflectionAttribute[] getAttributes(string $name = null, int $flags = 0)
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