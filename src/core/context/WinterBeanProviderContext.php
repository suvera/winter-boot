<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\enums\Allowable;
use dev\winterframework\exception\BeansDependencyException;
use dev\winterframework\exception\BeansException;
use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\proxy\ProxyGenerator;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\VariableResource;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\cli\Command;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\Qualifier;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\test\WinterBootTest;
use dev\winterframework\stereotype\Value;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\TypeCast;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use Throwable;
use TypeError;

final class WinterBeanProviderContext implements BeanProviderContext {
    /**
     * @var BeanProvider[]
     */
    protected array $beanNameFactory = [];

    /**
     * @var BeanProvider[][]
     */
    protected array $beanClassFactory = [];

    /**
     * @var BeanProvider[]
     */
    private array $beanResolutionOrder = [];

    private ClassResourceScanner $scanner;

    public function __construct(
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
        $this->scanner = ClassResourceScanner::getDefaultScanner();
    }

    public function addProviderClass(ClassResource $class): void {
        $this->validateBeanClass($class->getClass());
        $attributes = $class->getAttributes();

        $needProxy = ProxyGenerator::isProxyNeeded($class);

        foreach ($attributes as $attribute) {
            $this->processClassAttribute($class, $attribute, $needProxy);
        }

        foreach ($class->getMethods() as $method) {
            if ($needProxy) {
                $this->ctxData->getAopRegistry()->register($class, $method);
            }
            $this->addProviderMethod($class, $method);
        }
    }

    /**
     * @return BeanProvider[][]
     */
    public function getBeanClassFactory(): array {
        return $this->beanClassFactory;
    }

    /**
     * Process an Annotation at Class Level
     *
     * @param ClassResource $class
     * @param object $attribute
     * @param bool $needProxy
     */
    private function processClassAttribute(ClassResource $class, object $attribute, bool $needProxy): void {
        $attrClass = $attribute::class;

        switch ($attrClass) {
            case RequestMapping::class:
                // Not a Bean
                break;

            case Component::class:
            case Configuration::class:
            case RestController::class:
            case Service::class:
            case WinterBootTest::class:
            case Command::class:
            case WinterBootApplication::class:
                /** @var Component|Configuration|RestController|Service|WinterBootTest|Command $attribute */
                $beanProvider = new BeanProvider($class, null, $needProxy);
                $beanDef = new Bean($attribute->name);
                $this->registerBeanProvider($beanProvider, $beanDef);
                break;

            default:
                // ignore
                break;
        }
    }

    public function addProviderMethod(ClassResource $class, MethodResource $method): void {
        $attributes = $method->getAttributes();
        foreach ($attributes as $attribute) {
            $this->processMethodAttribute($class, $method, $attribute);
        }
    }

    /**
     * Process an Annotation at Class Level
     *
     * @param ClassResource $class
     * @param MethodResource $method
     * @param object $beanDef
     */
    private function processMethodAttribute(ClassResource $class, MethodResource $method, object $beanDef): void {
        $attrClass = $beanDef::class;

        if ($attrClass !== Bean::class) {
            return;
        }
        /** @var Bean $beanDef */

        /**
         * This cannot be validated as Interface maybe defined at #[Bean]
         */
        //$this->validateBeanClass($method->getReturnClass());
        $returnClass = $method->getReturnClass();
        if ($returnClass == null) {
            throw new BeansException($method->getReturnType() . ' for method '
                . ReflectionUtil::getFqName($method));
        }
        $needProxy = ProxyGenerator::isProxyNeeded($method->getReturnClass());

        $beanProvider = new BeanProvider($class, $method, $needProxy);
        $this->registerBeanProvider($beanProvider, $beanDef);
    }

    private function registerBeanProvider(BeanProvider $beanProvider, Bean $beanDef) {
        if ($beanDef->name) {
            if (isset($this->beanNameFactory[$beanDef->name])
                && !$this->beanNameFactory[$beanDef->name]->equals($beanProvider)) {
                throw new WinterException('Duplicate Bean name found at class definition '
                    . $beanProvider->toString()
                    . ', as it was already defined for class '
                    . $this->beanNameFactory[$beanDef->name]->toString()
                );
            }
            $this->beanNameFactory[$beanDef->name] = $beanProvider;
            $beanProvider->addNames($beanDef->name);
        }

        if ($beanProvider->getMethod() != null) {
            $className = $beanProvider->getMethod()->getReturnType();
        } else {
            $className = $beanProvider->getClass()->getClass()->getName();
            $this->registerBeanProviderClassAliases($beanProvider, $beanProvider->getClass()->getClass());
        }

        $this->beanClassFactory[$className][$className] = $beanProvider;
        $beanProvider->addNames($className);
    }

    private function registerBeanProviderClassAliases(
        BeanProvider $beanProvider,
        RefKlass $aliasable
    ): void {

        $aliases = [];
        $className = $aliasable->getName();
        $parentClass = $aliasable->getParentClass();
        $interfaces = $aliasable->getInterfaces();

        if (substr($className, -4) == 'Impl') {
            $interface = substr($className, 0, -4);
            if (is_a($className, $interface, true)
                && interface_exists($interface, true)) {
                $aliases[$interface] = $interface;
            }
        }

        $aliasableList = WinterInternalBeanAlias::getClassAliases();
        $parentList = [];
        if (isset($parentClass) && $parentClass) {
            $parentList[] = $parentClass;
        }
        if (isset($interfaces)) {
            foreach ($interfaces as $interface) {
                $parentList[] = $interface;
            }
        }

        foreach ($parentList as $parent) {
            if (isset($aliasableList[$parent->getName()])) {
                $def = $aliasableList[$parent->getName()];

                $intAliases = $def['aliases'];
                $intAliases = empty($intAliases) ? [$parent->getName()] : $intAliases;

                foreach ($intAliases as $alias) {
                    if (isset($this->beanClassFactory[$alias])
                        && $def['allowMultiple'] === Allowable::DISALLOW
                    ) {
                        $ex = '';
                        foreach ($this->beanClassFactory[$alias] as $cls => $beanPvdr) {
                            $ex .= ReflectionUtil::getFqName($beanPvdr->getClass()) . " \n";
                        }
                        throw new WinterException('Class ' . $alias
                            . ' has extended/implemented by multiple Beans. '
                            . "\n" . $className . " \n" . $ex
                        );
                    }
                    $aliases[$alias] = $alias;
                }
            }
        }

        foreach ($aliases as $alias) {
            $this->beanClassFactory[$alias][$alias] = $beanProvider;
            $beanProvider->addNames($alias);
        }
    }

    /**
     * Bean Public methods
     * ------------------------------
     *
     * @param string $name
     * @return object|null
     */
    public function beanByName(string $name): ?object {
        if (isset($this->beanNameFactory[$name])) {
            return $this->getInstance($this->beanNameFactory[$name]);
        }
        throw new BeansException($name);
    }

    public function beanByClass(string $class): ?object {
        if (isset($this->beanClassFactory[$class])) {

            if (count($this->beanClassFactory[$class]) > 1) {
                throw new NoUniqueBeanDefinitionException('No qualifying bean of type '
                    . "'$class' available: expected single matching bean but found "
                    . count($this->beanClassFactory[$class]) . ': '
                    . implode(', ', array_keys($this->beanClassFactory[$class]))
                );
            }

            $key = array_key_first($this->beanClassFactory[$class]);
            return $this->getInstance($this->beanClassFactory[$class][$key]);
        }
        throw new BeansException($class);
    }

    public function beanByNameClass(string $name, string $class): ?object {
        if (!isset($this->beanNameFactory[$name]) && !isset($this->beanClassFactory[$class])) {
            throw new BeansException($class);
        }

        foreach ($this->beanClassFactory[$class] as $beanClass => $resource) {
            if ($this->beanNameFactory[$name]->equals($resource)) {
                return $this->getInstance($resource);
            }
        }

        throw new BeansException($name . ' of type ' . $class);
    }

    public function hasBeanByName(string $name): bool {
        return isset($this->beanNameFactory[$name]);
    }

    public function hasBeanByClass(string $class): bool {
        return isset($this->beanClassFactory[$class]);
    }

    /**
     * -----------------------------------------------------------------------
     * Create Instance of given bean
     *
     * @param BeanProvider $beanProvider
     * @return object|null
     */
    private function getInstance(BeanProvider $beanProvider): ?object {
        $this->addToCircularDependency($beanProvider);

        if ($beanProvider->hasCached()) {
            $this->removeFromCircularDependency($beanProvider);
            return $beanProvider->getCached();
        }

        $method = $beanProvider->getMethod();

        if ($method !== null) {
            $bean = $this->buildInstanceByMethod($beanProvider);
        } else {
            $bean = $this->buildInstanceForClass($beanProvider);
        }
        $beanProvider->setCached($bean);

        $this->removeFromCircularDependency($beanProvider);
        return $bean;
    }

    private function addToCircularDependency(BeanProvider $beanProvider): void {
        $beanId = spl_object_hash($beanProvider);

        if (isset($this->beanResolutionOrder[$beanId])) {
            $msg = "|‾‾‾‾‾‾‾‾‾‾‾|\n";
            foreach ($this->beanResolutionOrder as $beanProv) {
                $msg .= '  -- ' . $beanProv->toString() . "\n";
            }
            $msg .= "|__________|\n";
            throw new BeansDependencyException("The dependencies of some of the beans "
                . "in the application context form a cycle: \n\n$msg\n"
            );
        }
        $this->beanResolutionOrder[$beanId] = $beanProvider;
    }

    private function removeFromCircularDependency(BeanProvider $beanProvider): void {
        $beanId = spl_object_hash($beanProvider);
        unset($this->beanResolutionOrder[$beanId]);
    }

    private function buildProxyClass(BeanProvider $beanProvider): ClassResource {
        if (!$beanProvider->isProxyUsed()) {
            return $beanProvider->getClass();
        }

        $className = ProxyGenerator::getProxyClassName($beanProvider->getClass()->getClass()->getName());

        if (!class_exists($className)) {
            eval(ProxyGenerator::getDefault()->generateClass($beanProvider->getClass()));
        }

        return $this->scanner->scanDefaultClass($className);
    }

    /**
     * Build BEAN Object from CLass definition
     *
     * @param BeanProvider $beanProvider
     * @return object
     */
    private function buildInstanceForClass(BeanProvider $beanProvider): object {
        $class = $this->buildProxyClass($beanProvider);

        try {
            /**
             * Circular Dependency lead to infinite Loop so,
             * we are creating object without calling Constructor
             */
            $bean = $class->getClass()->newInstanceWithoutConstructor();
        } catch (Throwable $e) {
            throw new WinterException("Could not instantiate object for class "
                . ReflectionUtil::getFqName($class->getClass()), 0, $e);
        }

        $this->injectProperties($bean, $class, $beanProvider);
        $this->callConstructor($bean, $class, $beanProvider);

        return $bean;
    }

    /** @noinspection PhpUnusedParameterInspection */
    private function injectProperties(object $bean, ClassResource $class, BeanProvider $beanProvider): void {
        $variables = $class->getVariables();

        foreach ($variables as $variable) {
            /** @var $variable VariableResource */

            /** @var Autowired $autoWired */
            $autoWired = $variable->getAttribute(Autowired::class);
            if ($autoWired !== null) {
                $this->injectAutowired($autoWired, $bean);
            }

            /** @var Value $autoValue */
            $autoValue = $variable->getAttribute(Value::class);
            if ($autoValue !== null) {
                $this->injectAutoValue($autoValue, $bean);
            }
        }
    }

    private function injectAutowired(Autowired $autoWired, object $bean): void {
        if ($autoWired->name) {
            $childBean = $this->beanByNameClass($autoWired->name, $autoWired->getTargetType());
        } else {
            $childBean = $this->beanByClass($autoWired->getTargetType());
        }

        $autoWired->getRefOwner()->setAccessible(true);
        if ($autoWired->isTargetStatic()) {
            $autoWired->getRefOwner()->setValue($childBean);
        } else {
            $autoWired->getRefOwner()->setValue($bean, $childBean);
        }
    }

    private function injectAutoValue(Value $autoValue, object $bean): void {
        $autoValue->getRefOwner()->setAccessible(true);

        $ymlName = substr($autoValue->name, 2, -1);
        if ($this->ctxData->getPropertyContext()->has($ymlName)) {

            $val = $this->ctxData->getPropertyContext()->get($ymlName);

        } else if (isset($autoValue->defaultValue)) {

            $val = TypeCast::parseValue($autoValue->getTargetType(), $ymlName);

        } else if (!$autoValue->getRefOwner()->hasDefaultValue()) {

            throw new WinterException('Could not find config property #[Value] "'
                . $autoValue->name
                . '", so, Could not instantiate object for class '
                . get_class($bean)
            );

        } else {
            return;
        }

        if ($autoValue->isTargetStatic()) {
            $autoValue->getRefOwner()->setValue($val);
        } else {
            $autoValue->getRefOwner()->setValue($bean, $val);
        }
    }

    private function callConstructor(object $bean, ClassResource $class, BeanProvider $beanProvider): void {
        $constructor = $class->getClass()->getConstructor();
        if ($constructor === null) {
            return;
        }

        $this->callObjectMethod($bean, $constructor, $beanProvider);
    }

    /** @noinspection PhpUnusedParameterInspection */
    private function callObjectMethod(
        object $bean,
        ReflectionMethod $method,
        BeanProvider $beanProvider
    ): mixed {
        $args = [];
        foreach ($method->getParameters() as $parameter) {
            /** @var ReflectionNamedType $type */
            $type = $parameter->getType();

            $this->validateBeanMethodParam($method, $parameter);

            if ($type->isBuiltin()) {
                continue;
            }

            $qualifiers = $parameter->getAttributes(Qualifier::class);
            if (!empty($qualifiers)) {
                /** @var Qualifier $attr */
                $attr = $qualifiers[0]->newInstance();
                $bean = $this->beanByName($attr->name);
            } else {
                $bean = $this->beanByClass($type->getName());
            }

            $args[$parameter->getName()] = $bean;
        }

        try {
            return $method->invokeArgs($bean, $args);
        } catch (Throwable $e) {
            throw new WinterException("Could not call method "
                . ReflectionUtil::getFqName($method), 0, $e);
        }
    }

    private function buildInstanceByMethod(
        BeanProvider $beanProvider
    ): object {

        $method = $beanProvider->getMethod();
        $providerBean = $this->beanByClass($beanProvider->getClass()->getClass()->getName());
        $m = $method->getMethod();

        $bean = $this->callObjectMethod($providerBean, $m->getDelegate(), $beanProvider);
        if (!isset($bean)) {
            throw new BeansException('' . $m->getReturnType()->getName()
                . ', out of #[Bean] method '
                . ReflectionUtil::getFqName($m)
            );
        }

        return $bean;
    }

    private function validateBeanClass(RefKlass $cls) {
        if (!$cls->isInstantiable()) {
            throw new TypeError('Class ' . ReflectionUtil::getFqName($cls)
                . ' cannot be Instantiable');
        }

        if ($cls->isFinal()) {
            throw new TypeError('Class ' . ReflectionUtil::getFqName($cls)
                . ' must not be FINAL ');
        }

        $constructor = $cls->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $this->validateBeanMethodParam($constructor, $parameter);
            }
        }
    }

    private function validateBeanMethodParam(
        ReflectionMethod $method,
        ReflectionParameter $parameter
    ): void {
        /** @var ReflectionNamedType $type */
        $type = $parameter->getType();
        if ($type === null) {
            throw new TypeError("Arguments for Method "
                . ReflectionUtil::getFqName($method)
                . " must have Type Hinted"
            );
        }

        if ($type->isBuiltin()) {
            if (!$parameter->isDefaultValueAvailable()) {
                throw new TypeError("Method "
                    . ReflectionUtil::getFqName($method)
                    . " has parameter " . ReflectionUtil::getFqName($parameter)
                    . " without default value, so cannot instantiate this class"
                );
            }
        }
    }

    /**
     * Register internal Beans, so that they can be autowired
     *
     * @param object $bean
     * @param string $beanClass
     * @param bool $overwrite
     */
    public function registerInternalBean(
        object $bean,
        string $beanClass = '',
        bool $overwrite = true
    ): void {
        $beanClass = empty($beanClass) ? $bean::class : $beanClass;

        if (!$overwrite && $this->hasBeanByClass($beanClass)) {
            return;
        }

        $ref = new ReflectionObject($bean);
        foreach ($ref->getProperties() as $prop) {
            $autowired = $prop->getAttributes(Autowired::class);
            if (count($autowired) > 0) {
                $prop->setAccessible(true);
                /** @noinspection PhpPossiblePolymorphicInvocationInspection */
                $prop->setValue($bean, $this->beanByClass($prop->getType()->getName()));
            }
        }

        try {
            $ref = new RefKlass($beanClass);
        } catch (Throwable $e) {
            throw new ClassNotFoundException('Could not load class "' . $beanClass,
                0, $e
            );
        }
        $class = new ClassResource();
        $class->setClass($ref);

        $beanProvider = new BeanProvider($class);
        // Validation here ?
        unset($this->beanClassFactory[$beanClass]);

        $beanProvider->setCached($bean);
        $this->beanClassFactory[$beanClass][$beanClass] = $beanProvider;
    }

}