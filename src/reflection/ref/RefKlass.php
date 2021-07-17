<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\ref;


use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @method string __toString()
 * @method string getName()
 * @method bool isInternal()
 * @method bool isUserDefined()
 * @method bool isAnonymous()
 * @method bool isInstantiable()
 * @method bool isCloneable()
 * @method mixed getFileName()
 * @method mixed getStartLine()
 * @method mixed getEndLine()
 * @method mixed getDocComment()
 * @method ReflectionMethod getConstructor()
 * @method bool hasMethod(string $name)
 * @method ReflectionMethod getMethod(string $name)
 * @method ReflectionMethod[] getMethods(int $filter = null)
 * @method bool hasProperty(string $name)
 * @method mixed getProperty(string $name)
 * @method ReflectionProperty[] getProperties(int $filter = null)
 * @method bool hasConstant(string $name)
 * @method mixed getConstants(int $filter = null)
 * @method mixed getReflectionConstants(int $filter = null)
 * @method mixed getConstant(string $name)
 * @method mixed getReflectionConstant(string $name)
 * @method ReflectionClass[] getInterfaces()
 * @method mixed getInterfaceNames()
 * @method bool isInterface()
 * @method mixed getTraits()
 * @method mixed getTraitNames()
 * @method mixed getTraitAliases()
 * @method bool isTrait()
 * @method bool isAbstract()
 * @method bool isFinal()
 * @method mixed getModifiers()
 * @method bool isInstance(object $object)
 * @method mixed newInstance(mixed ...$args)
 * @method mixed newInstanceWithoutConstructor()
 * @method mixed newInstanceArgs(array $args = [])
 * @method mixed getParentClass()
 * @method mixed isSubclassOf(ReflectionClass|string $class)
 * @method mixed getStaticProperties()
 * @method mixed getStaticPropertyValue(string $name, mixed $default)
 * @method mixed setStaticPropertyValue(string $name, mixed $value)
 * @method mixed getDefaultProperties()
 * @method bool isIterable()
 * @method bool isIterateable()
 * @method bool implementsInterface(ReflectionClass|string $interface)
 * @method mixed getExtension()
 * @method mixed getExtensionName()
 * @method bool inNamespace()
 * @method string getNamespaceName()
 * @method string getShortName()
 * @method ReflectionAttribute[] getAttributes(string $name = null, int $flags = 0)
 */
class RefKlass extends ReflectionAbstract {

    public static function getInstance(string|ReflectionClass|RefKlass $class): self {
        if ($class instanceof RefKlass) {
            return $class;
        }
        $obj = new self();

        if (is_string($class)) {
            $obj->_data['class'] = $class;
            $obj->delegate = $obj->loadDelegate();
        } else {
            $obj->_data['class'] = $class->getName();
            $obj->delegate = $class;
        }

        return $obj;
    }

    public function __construct(object|string $objectOrClass = null) {
        if (is_string($objectOrClass)) {
            $this->_data['class'] = $objectOrClass;
            $this->delegate = $this->loadDelegate();
        } else if (is_object($objectOrClass)) {
            $this->_data['class'] = $objectOrClass::class;
            $this->delegate = $this->loadDelegate();
        }
    }

    protected function loadDelegate(): ReflectionClass {
        return ReflectionRegistry::getClass($this->_data['class']);
    }


}