<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\stereotype\StereoTyped;
use dev\winterframework\type\AttributeList;
use dev\winterframework\type\StringList;
use dev\winterframework\util\log\Wlf4p;
use ReflectionAttribute;
use Throwable;
use UnexpectedValueException;

class ClassResourceScanner {
    use Wlf4p;

    private static StringList $defaultStereoTypes;

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
     * Scan a Class for default Attributes
     */
    public function scanClass(
        string $fqns,
        StringList $attributes
    ): ?ClassResource {
        try {
            $ref = new RefKlass($fqns);
        } catch (Throwable $e) {
            self::logException($e);
            return null;
        }

        $lookForAttrs = [];
        foreach ($attributes as $attribute) {
            $lookForAttrs[$attribute] = $attribute;
        }

        return $this->buildClassResource($ref, $lookForAttrs, $attributes);
    }

    /*
     * Scan for given Attributes
     */
    public function scan(
        Psr4Namespaces $namespaces,
        StringList $attributes,
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
        StringList $attributes,
        bool $autoload = false,
        array $excludeNamespaces = [],
        bool $excludeClsWithoutAttrs = false
    ): ClassResources {
        $resources = ClassResources::ofValues();

        $lookForAttrs = [];
        foreach ($attributes as $attribute) {
            $lookForAttrs[$attribute] = $attribute;
        }
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
            $lookForAttrs[$stereoType] = $stereoType;
            $attributes[] = $stereoType;
        }

        /**
         * STEP - 2: Find Class Resources
         */
        foreach ($files as $fqns => $file) {
            $res = $this->buildClassFileResource(
                $fqns,
                $file,
                $lookForAttrs,
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
        array $lookForAttrs,
        StringList $attributes,
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
                $lookForAttrs,
                $attributes,
                $excludeClsWithoutAttrs
            );
        } catch (Throwable $e) {
            self::logException($e);
            return null;
        }
    }

    private function buildClassResource(
        RefKlass $ref,
        array $lookForAttrs,
        StringList $attributes,
        bool $excludeClsWithoutAttrs = false
    ): ?ClassResource {
        $attrList = $this->scanAttributes($ref->getAttributes(), $ref, $lookForAttrs);

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

        $methods = $ref->getMethods();
        foreach ($methods as $methodR) {
            $method = RefMethod::getInstance($methodR);
            $methAttrs = $this->scanAttributes($method->getAttributes(), $method, $lookForAttrs);

            if ($methAttrs) {
                $meth = new MethodResource();
                $meth->setMethod($method);
                $meth->setAttributes(AttributeList::ofArray($methAttrs));
                $type = $meth->getReturnNamedType();
                if (!$type->isNoType() && !$type->isBuiltin()) {
                    echo "\n" . $type->getName() . "\n";
                    $meth->setReturnClass($this->scanClass($type->getName(), $attributes));
                }
                $methList[] = $meth;
            }
        }

        $vars = $ref->getProperties();
        foreach ($vars as $varA) {
            $var = RefProperty::getInstance($varA);
            $varAttrs = $this->scanAttributes($var->getAttributes(), $var, $lookForAttrs);

            if ($varAttrs) {
                $variable = new VariableResource();
                $variable->setVariable($var);
                $variable->setAttributes(AttributeList::ofArray($varAttrs));
                $varList[] = $variable;
            }
        }

        return $res;
    }

    /**
     * @param array|ReflectionAttribute[] $attrs
     * @param object $target
     * @param array $lookForAttrs
     * @return array
     */
    private function scanAttributes(array $attrs, object $target, array $lookForAttrs): array {
        $attrList = [];
        foreach ($attrs as $attr) {
            if (isset($lookForAttrs[$attr->getName()])) {
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

    private function getDefaultStereoTypes(): StringList {
        if (!isset(self::$defaultStereoTypes)) {
            self::$defaultStereoTypes = StringList::ofArray(
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