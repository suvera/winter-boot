<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\actuator\stereotype\HealthInformer;
use dev\winterframework\actuator\stereotype\InfoInformer;
use dev\winterframework\exception\BeansDependencyException;
use dev\winterframework\exception\BeansException;
use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\proxy\ProxyGenerator;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\cli\Command;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\Module;
use dev\winterframework\stereotype\PostConstruct;
use dev\winterframework\stereotype\Qualifier;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\test\WinterBootTest;
use dev\winterframework\stereotype\Value;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\WinterBootApplication;
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

    public function __construct(
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
    }

    public function addProviderClass(ClassResource $class): void {
        $this->_addProviderClass($class);
    }

    public function addProviderClassAs(ClassResource $class, array $attributes): void {
        $this->_addProviderClass($class, $attributes);
    }

    private function _addProviderClass(ClassResource $class, array $moreAttributes = null): void {
        $this->validateBeanClass($class->getClass());
        $attributes = $class->getAttributes();
        if ($moreAttributes) {
            $attributes->addAll($moreAttributes);
        }

        foreach ($attributes as $attribute) {
            $this->processClassAttribute($class, $attribute);
        }

        foreach ($class->getMethods() as $method) {
            if ($method->isProxyNeeded()) {
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
     */
    private function processClassAttribute(ClassResource $class, object $attribute): void {
        $attrClass = $attribute::class;

        if ($attribute instanceof RequestMapping) {
            // Not a Bean
            return;
        }

        switch ($attrClass) {
            case Component::class:
            case Configuration::class:
            case RestController::class:
            case Service::class:
            case WinterBootTest::class:
            case Command::class:
            case WinterBootApplication::class:
            case Module::class:
                /**
                 * @var Component|Configuration|RestController|Service $attribute
                 * @var WinterBootTest|Command|Module $attribute
                 */
                $beanProvider = new BeanProvider($class, null, $class->isProxyNeeded());
                $beanDef = new Bean($attribute->name);
                if ($attrClass == Module::class) {
                    $beanDef->destroyMethod = $attribute->destroyMethod ?: null;
                }
                $this->registerBeanProvider($beanProvider, $beanDef);
                break;

            case HealthInformer::class:
            case InfoInformer::class:
                if (
                    $this->ctxData->getAttributesToScan()->offsetExists($attrClass)
                    && !$this->hasBeanByClass($class->getClass()->getName())
                ) {
                    /** @var HealthInformer|InfoInformer $attribute */
                    $beanProvider = new BeanProvider($class, null, $class->isProxyNeeded());
                    $beanDef = new Bean();
                    $this->registerBeanProvider($beanProvider, $beanDef);
                }
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

        $beanProvider = new BeanProvider($class, $method, $returnClass->isProxyNeeded());
        $this->registerBeanProvider($beanProvider, $beanDef);
    }

    private function registerBeanProvider(BeanProvider $beanProvider, Bean $beanDef) {
        if ($beanDef->name) {
            if (
                isset($this->beanNameFactory[$beanDef->name])
                && !$this->beanNameFactory[$beanDef->name]->equals($beanProvider)
            ) {
                throw new WinterException(
                    'Duplicate Bean name found at class definition '
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

        $beanProvider->setInitMethod($beanDef->initMethod);
        $beanProvider->setDestroyMethod($beanDef->destroyMethod);

        $this->beanClassFactory[$className][$className] = $beanProvider;
        $beanProvider->addNames($className);

        if ($beanProvider->hasDestroyMethod()) {
            $this->ctxData->getShutDownRegistry()->registerBeanProvider($beanProvider);
        }
    }

    private function registerBeanProviderClassAliases(
        BeanProvider $beanProvider,
        RefKlass $aliasable
    ): void {

        $aliases = [];
        $className = $aliasable->getName();
        $interfaces = $aliasable->getInterfaces();

        if (str_ends_with($className, 'Impl')) {
            foreach ($interfaces as $interface) {
                $aliases[$interface->getName()] = $interface->getName();
            }
        }

        foreach ($aliases as $alias) {
            $this->beanClassFactory[$alias][$className] = $beanProvider;
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
                throw new NoUniqueBeanDefinitionException(
                    'No qualifying bean of type '
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
        if (!isset($this->beanNameFactory[$name])) {
            throw new BeansException($name);
        }

        $obj = $this->getInstance($this->beanNameFactory[$name]);

        if (!$obj instanceof $class) {
            throw new BeansException($name . ' of type ' . $class);
        }

        return $obj;
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

        $this->postConstruct($bean, $beanProvider);

        $beanProvider->setCached($bean);

        $this->removeFromCircularDependency($beanProvider);
        return $bean;
    }

    protected function postConstruct(object $bean, BeanProvider $beanProvider): void {

        $methodsCalled = [];
        if ($beanProvider->hasInitMethod()) {
            $initMethod = $beanProvider->getInitMethod();
            $bean->$initMethod();
            $methodsCalled[$initMethod] = 1;
        }
        $ref = new RefKlass($bean);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (isset($methodsCalled[$method->getShortName()])) {
                continue;
            }
            $pc = $method->getAttributes(PostConstruct::class);
            if ($pc) {
                $args = $this->buildMethodArguments($method);

                try {
                    $method->invokeArgs($bean, $args);
                } catch (Throwable $e) {
                    throw new WinterException("Could not call #[PostConstruct] method "
                        . ReflectionUtil::getFqName($method), 0, $e);
                }
                $methodsCalled[$method->getShortName()] = 1;
            }
        }
    }


    private function addToCircularDependency(BeanProvider $beanProvider): void {
        $beanId = spl_object_hash($beanProvider);

        if (isset($this->beanResolutionOrder[$beanId])) {
            $msg = "|‾‾‾‾‾‾‾‾‾‾‾|\n";
            foreach ($this->beanResolutionOrder as $beanProv) {
                $msg .= '  -- ' . $beanProv->toString() . "\n";
            }
            $msg .= "|__________|\n";
            throw new BeansDependencyException(
                "The dependencies of some of the beans "
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

        $clsRes = $this->appCtx->addClass($className);

        $clsRes->getAttributes()->addAll($beanProvider->getClass()->getAttributes()->getArray());
        $clsRes->getVariables()->addAll($beanProvider->getClass()->getVariables()->getArray());

        return $clsRes;
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
        ReflectionUtil::performAutoWired($this->appCtx, $autoWired, $bean);
    }

    private function injectAutoValue(Value $autoValue, object $bean): void {
        ReflectionUtil::performAutoValue($this->appCtx, $autoValue, $bean);
    }

    private function callConstructor(object $bean, ClassResource $class, BeanProvider $beanProvider): void {
        $constructor = $class->getClass()->getConstructor();
        if ($constructor === null) {
            return;
        }

        $this->callObjectMethod($bean, $constructor, $beanProvider);
    }

    private function callObjectMethod(
        object $bean,
        ReflectionMethod $method,
        BeanProvider $beanProvider
    ): mixed {
        if ($beanProvider->hasMethodArgs()) {
            $args = $beanProvider->getMethodArgs();
        } else {
            $args = $this->buildMethodArguments($method);
        }

        try {
            return $method->invokeArgs($bean, $args);
        } catch (Throwable $e) {
            throw new WinterException("Could not call method, " . $e->getMessage() . ' '
                . ReflectionUtil::getFqName($method), 0, $e);
        }
    }

    protected function buildMethodArguments(ReflectionMethod $method): array {
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
                $beanArg = $this->beanByName($attr->name);
            } else {
                $beanArg = $this->beanByClass($type->getName());
            }

            $args[$parameter->getName()] = $beanArg;
        }

        return $args;
    }

    private function buildInstanceByMethod(
        BeanProvider $beanProvider
    ): object {

        $method = $beanProvider->getMethod();
        if ($beanProvider->hasProviderObject()) {
            $providerBean = $beanProvider->getProviderObject();
        } else {
            $providerBean = $this->beanByClass($beanProvider->getClass()->getClass()->getName());
        }

        $m = $method->getMethod();

        $bean = $this->callObjectMethod($providerBean, $m->getDelegate(), $beanProvider);
        if (!isset($bean)) {
            throw new BeansException(
                '' . $m->getReturnType()->getName()
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
            throw new TypeError(
                "Arguments for Method "
                    . ReflectionUtil::getFqName($method)
                    . " must have Type Hinted"
            );
        }

        if ($type->isBuiltin()) {
            if (!$parameter->isDefaultValueAvailable()) {
                throw new TypeError(
                    "Method "
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
     * @param bool $overwriteClass
     * @param string $beanName
     * @param bool $overwriteName
     */
    public function registerInternalBean(
        object $bean,
        string $beanClass = '',
        bool $overwriteClass = true,
        string $beanName = '',
        bool $overwriteName = false
    ): void {
        $beanClass = empty($beanClass) ? $bean::class : $beanClass;

        $clsOverWritable = ($overwriteClass || !$this->hasBeanByClass($beanClass));
        $nameOverWritable = ($beanName && ($overwriteName || !$this->hasBeanByName($beanName)));

        if (!$clsOverWritable && !$nameOverWritable) {
            return;
        }

        $ref = new ReflectionObject($bean);
        foreach ($ref->getProperties() as $prop) {
            $autowired = $prop->getAttributes(Autowired::class);
            if (count($autowired) > 0) {
                $prop->setAccessible(true);
                $prop->setValue($bean, $this->beanByClass($prop->getType()->getName()));
            }
        }

        try {
            $ref = new RefKlass($beanClass);
        } catch (Throwable $e) {
            throw new ClassNotFoundException(
                'Could not load class "' . $beanClass,
                0,
                $e
            );
        }
        $class = new ClassResource();
        $class->setClass($ref);

        $beanProvider = new BeanProvider($class);
        $beanProvider->setCached($bean);

        if ($clsOverWritable) {
            unset($this->beanClassFactory[$beanClass]);
            $this->beanClassFactory[$beanClass][$beanClass] = $beanProvider;
        }
        if ($nameOverWritable) {
            $this->beanNameFactory[$beanName] = $beanProvider;
        }
    }

    /**
     * Register internal Beans (by Method provider), so that they can be autowired
     *
     * @param string $beanName
     * @param string $beanClassName
     * @param object $provider
     * @param string $methodName
     * @param array $methodArgs
     * @param bool $overwrite
     */
    public function registerInternalBeanMethod(
        string $beanName,
        string $beanClassName,
        object $provider,
        string $methodName,
        array $methodArgs = [],
        bool $overwrite = true
    ): void {

        $ref = new RefKlass($provider);
        $class = new ClassResource();
        $class->setClass($ref);

        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance($class->getClass()->getMethod($methodName)));

        $beanProvider = new BeanProvider($class, $methRes);
        $beanProvider->setProviderObject($provider);
        $beanProvider->setMethodArgs($methodArgs);

        if (!empty($beanName)) {
            if (!$overwrite && $this->hasBeanByName($beanName)) {
                return;
            }
            $this->beanNameFactory[$beanName] = $beanProvider;
            $beanProvider->addNames($beanName);
        }

        if (!empty($beanClassName)) {
            if (!$overwrite && $this->hasBeanByClass($beanClassName)) {
                return;
            }

            // Validation here ?
            unset($this->beanClassFactory[$beanClassName]);
            $this->beanClassFactory[$beanClassName][$beanClassName] = $beanProvider;
        }
    }
}
