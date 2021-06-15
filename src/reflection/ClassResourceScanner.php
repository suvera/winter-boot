<?php
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\support\MethodParameter;
use dev\winterframework\reflection\support\MethodParameters;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\stereotype\StereoTyped;
use dev\winterframework\task\async\stereotype\Async;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\type\AttributeList;
use dev\winterframework\type\StringSet;
use dev\winterframework\util\log\Wlf4p;
use ReflectionAttribute;
use ReflectionMethod;
use Throwable;
use UnexpectedValueException;

class ClassResourceScanner {
    use Wlf4p;

    private static StringSet $defaultStereoTypes;

    private static ClassResourceScanner $instance;
    /**
     * @var string[]
     */
    private static array $stereoTypes;

    private function __construct() {
    }

    public static function getDefaultScanner(): ClassResourceScanner {
        if (!isset(self::$instance)) {
            self::$instance = new self();

            self::$stereoTypes = [
                StereoTyped::class => StereoTyped::class
            ];
        }

        return self::$instance;
    }

    /*
     * Scan for default Attributes
     */
    public function scanDefault(
        Psr4Namespaces $namespaces,
        bool $autoload = false,
        array $excludeNamespaces = []
    ): ClassResources {
        $attributes = $this->getDefaultStereoTypes();

        return $this->doScan(
            $namespaces,
            $attributes,
            $autoload,
            $excludeNamespaces,
            true
        );
    }

    /*
     * Scan a Class for default Attributes
     */
    public function scanDefaultClass(string $fqns): ?ClassResource {
        $attributes = $this->getDefaultStereoTypes();
        return $this->scanClass($fqns, $attributes);
    }

    /*
     * Scan a Class for Attributes
     */
    public function scanClass(
        string $fqns,
        StringSet $attributes
    ): ?ClassResource {
        try {
            $ref = new RefKlass($fqns);
        } catch (Throwable $e) {
            self::logException($e);
            return null;
        }

        return $this->buildClassResource($ref, $attributes);
    }

    /*
     * Scan a Class recursively
     */
    public function scanClassRecursive(
        string $fqns,
        StringSet $attributes,
        ClassResources $resources = null
    ): ClassResources {

        if (is_null($resources)) {
            $resources = ClassResources::ofValues();
        }
        try {
            $ref = new RefKlass($fqns);
        } catch (Throwable $e) {
            self::logException($e);
            return $resources;
        }

        $res = $this->buildClassResource($ref, $attributes);
        if (is_null($res)) {
            return $resources;
        }

        $resources[] = $res;

        foreach ($res->getVariables() as $variable) {
            /** @var VariableResource $variable */
            if ($variable->getAttributes()->count() === 0) {
                continue;
            }

            foreach ($variable->getAttributes() as $attribute) {
                if ($attribute instanceof ScanClassProvider) {
                    foreach ($attribute->provideClasses() as $provided) {
                        if (!isset($resources[$provided])) {
                            $this->scanClassRecursive($provided, $attributes, $resources);
                        }
                    }
                }
            }

            $type = $variable->getParameterType();
            if ($type->isNoType() || $type->isBuiltin()) {
                continue;
            }

            if (!$resources->offsetExists($type->getName())) {
                if (!isset($resources[$type->getName()])) {
                    $this->scanClassRecursive($type->getName(), $attributes, $resources);
                }
            }
        }

        foreach ($res->getMethods() as $method) {
            /** @var MethodResource $method */
            if ($method->getAttributes()->count() === 0) {
                continue;
            }

            foreach ($method->getAttributes() as $attribute) {
                if ($attribute instanceof ScanClassProvider) {
                    foreach ($attribute->provideClasses() as $provided) {
                        if (!isset($resources[$provided])) {
                            $this->scanClassRecursive($provided, $attributes, $resources);
                        }
                    }
                }
            }

            $type = $method->getReturnNamedType();
            if ($type->isNoType() || $type->isBuiltin()) {
                continue;
            }

            if (!$resources->offsetExists($type->getName())) {
                if (!isset($resources[$type->getName()])) {
                    $this->scanClassRecursive($type->getName(), $attributes, $resources);
                }
            }
        }

        foreach ($res->getAttributes() as $attribute) {
            if ($attribute instanceof ScanClassProvider) {
                foreach ($attribute->provideClasses() as $provided) {
                    if (!isset($resources[$provided])) {
                        $this->scanClassRecursive($provided, $attributes, $resources);
                    }
                }
            }
        }

        return $resources;
    }

    /*
     * Scan for given Attributes
     */
    public function scan(
        Psr4Namespaces $namespaces,
        StringSet $attributes,
        bool $autoload = false,
        array $excludeNamespaces = [],
    ): ClassResources {
        return $this->doScan(
            $namespaces,
            $attributes,
            $autoload,
            $excludeNamespaces,
            true
        );
    }

    private function doScan(
        Psr4Namespaces $namespaces,
        StringSet $attributes,
        bool $autoload = false,
        array $excludeNamespaces = [],
        bool $excludeClsWithoutAttrs = false
    ): ClassResources {
        $resources = ClassResources::ofValues();

        $files = [];
        foreach ($namespaces as $namespace) {
            /** @var Psr4Namespace $namespace */
            $files = array_merge(
                $files,
                DirectoryScanner::scanForPhpClasses(
                    $namespace->getBaseDirectory(),
                    $namespace->getNamespacePrefix(),
                    $excludeNamespaces
                )
            );
        }
        $files = array_unique($files);

        /**
         * STEP - 1: Look for StereoTypes
         */
        $stereoTypes = $this->findStereoTypes($files, $autoload);
        foreach ($stereoTypes as $stereoType) {
            $attributes[] = $stereoType;
        }

        /**
         * STEP - 2: Find Class Resources
         */
        foreach ($files as $fqns => $file) {
            $res = $this->buildClassFileResource(
                $fqns,
                $file,
                $attributes,
                $autoload,
                $excludeClsWithoutAttrs
            );

            if ($res) {
                $resources[] = $res;
            }
        }

        return $resources;
    }

    private function buildClassFileResource(
        string $fqns,
        string $file,
        StringSet $attributes,
        bool $autoload = false,
        bool $excludeClsWithoutAttrs = false
    ): ?ClassResource {
        try {
            if ($autoload && !class_exists($fqns)) {
                /** @noinspection PhpIncludeInspection */
                require_once($file);
            }
            $ref = new RefKlass($fqns);
            return $this->buildClassResource(
                $ref,
                $attributes,
                $excludeClsWithoutAttrs
            );
        } catch (Throwable $e) {
            self::logException($e);
            //return null;
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $e;
        }
    }

    private function buildClassResource(
        RefKlass $ref,
        StringSet $attributes,
        bool $excludeClsWithoutAttrs = false
    ): ?ClassResource {
        $attrList = $this->scanAttributes($ref->getAttributes(), $ref, $attributes);

        if ($excludeClsWithoutAttrs && count($attrList) <= 0) {
            return null;
        }

        $res = new ClassResource();
        $res->setAttributes(AttributeList::ofArray($attrList));
        $res->setClass(RefKlass::getInstance($ref));
        $methList = MethodResources::ofValues();
        $res->setMethods($methList);
        $varList = VariableResources::ofValues();
        $res->setVariables($varList);
        $proxyMethList = MethodResources::ofValues();
        $res->setProxyMethods($proxyMethList);

        $methods = $ref->getMethods();
        foreach ($methods as $methodR) {
            $method = RefMethod::getInstance($methodR);
            $methAttrs = $this->scanAttributes($method->getAttributes(), $method, $attributes);

            if ($methAttrs) {
                $meth = new MethodResource();
                $meth->setMethod($method);
                $meth->setAttributes(AttributeList::ofArray($methAttrs));
                $type = $meth->getReturnNamedType();
                if (!$type->isNoType() && !$type->isBuiltin()) {
                    $meth->setReturnClass($this->scanClass($type->getName(), $attributes));
                }
                $meth->setParameters($this->scanMethodParameters($methodR));
                $methList[] = $meth;

                foreach ($methAttrs as $methAttr) {
                    if ($methAttr instanceof AopStereoType
                        || $methAttr instanceof Async
                        || $methAttr instanceof Scheduled
                    ) {
                        $meth->setProxyNeeded(true);
                        $res->setProxyNeeded(true);

                        if ($methAttr instanceof AopStereoType) {
                            $meth->setAopProxy(true);
                        }
                        if ($methAttr instanceof Async) {
                            $meth->setAsyncProxy(true);
                        }
                        if ($methAttr instanceof Scheduled) {
                            $meth->setScheduledProxy(true);
                        }
                        $proxyMethList[$meth->getMethod()->getName()] = $meth;
                    }
                }
            }
        }

        $vars = $ref->getProperties();
        foreach ($vars as $varA) {
            $var = RefProperty::getInstance($varA);
            $varAttrs = $this->scanAttributes($var->getAttributes(), $var, $attributes);

            if ($varAttrs) {
                $variable = new VariableResource();
                $variable->setVariable($var);
                $variable->getParameterType();
                $variable->setAttributes(AttributeList::ofArray($varAttrs));
                $varList[] = $variable;
            }
        }

        return $res;
    }

    private function scanMethodParameters(ReflectionMethod $method): MethodParameters {
        $m = MethodParameters::ofValues();

        foreach ($method->getParameters() as $p) {
            $m[] = MethodParameter::fromReflection($p);
        }

        return $m;
    }

    /**
     * @param array|ReflectionAttribute[] $attrs
     * @param object $target
     * @param StringSet $attributes
     * @return array
     */
    private function scanAttributes(array $attrs, object $target, StringSet $attributes): array {
        $attrList = [];
        foreach ($attrs as $attr) {
            if (isset($attributes[$attr->getName()])) {
                $attrList[] = $this->buildAttribute($attr, $target);
            }
        }

        return $attrList;
    }

    private function buildAttribute(ReflectionAttribute $attr, object $target): object {
        try {
            $obj = $attr->newInstance();
        } catch (Throwable $e) {
            throw new UnexpectedValueException('Invalid Attribute '
                . $attr->getName() . ' in wrong place ', 0, $e);
        }

        /** @var StereoType $obj */
        $obj->init($target);

        return $obj;
    }

    public function getDefaultStereoTypes(): StringSet {
        if (!isset(self::$defaultStereoTypes)) {
            self::$defaultStereoTypes = StringSet::ofArray(
                array_keys(
                    DirectoryScanner::scanForPhpClasses(
                        dirname(__DIR__) . '/stereotype',
                        'dev\\winterframework\\stereotype'
                    )
                )
            );
        }

        return self::$defaultStereoTypes;
    }

    private function findStereoTypes(
        array $files,
        bool $autoload
    ): array {
        $stereoTypes = [];
        foreach ($files as $fqns => $file) {

            if ($autoload && !class_exists($fqns)) {
                /** @noinspection PhpIncludeInspection */
                require_once($file);
            }

            try {
                if ($autoload && !class_exists($fqns)) {
                    /** @noinspection PhpIncludeInspection */
                    require_once($file);
                }
                $ref = new RefKlass($fqns);

                $attrs = $ref->getAttributes();
                if (count($attrs) == 0) {
                    continue;
                }

                foreach ($attrs as $attr) {
                    if (isset(self::$stereoTypes[$attr->getName()])) {
                        $this->buildAttribute($attr, $ref);
                        $stereoTypes[$ref->getName()] = $ref->getName();
                    }
                }
            } catch (Throwable $e) {
                self::logException($e);
                continue;
            }
        }

        return $stereoTypes;
    }

}