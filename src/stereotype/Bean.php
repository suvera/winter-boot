<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;
use ReflectionUnionType;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class Bean implements StereoType {

    public string $returnType = '';
    private RefMethod $refOwner;

    public function __construct(
        public string $name = '',
        public ?string $initMethod = null,
        public ?string $destroyMethod = null
    ) {
    }

    public function getReturnType(): string {
        return $this->returnType;
    }

    public function getRefOwner(): RefMethod {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        if ($ref->isConstructor() || $ref->isDestructor()) {
            throw new TypeError("#[Bean] Annotation is not allowed on Constructor/Destructor "
                . ReflectionUtil::getFqName($ref));
        }

        $config = $ref->getDeclaringClass()->getAttributes(Configuration::class);
        if (empty($config)) {
            throw new TypeError("Method Annotated with #[Bean] "
                . "must have it's class also annotated with #[Configuration] at method "
                . ReflectionUtil::getFqName($ref));
        }

        if (!$ref->hasReturnType()) {
            throw new TypeError("Method Annotated with #[Bean] must have return Type declared on Method "
                . ReflectionUtil::getFqName($ref));
        }
        /** @var ReflectionNamedType $retType */
        $retType = $ref->getReturnType();

        if ($retType instanceof ReflectionUnionType) {
            throw new TypeError("Method Annotated with #[Bean] must NOT be UNION type, Method: "
                . ReflectionUtil::getFqName($ref));
        }

        if ($retType->isBuiltin()) {
            throw new TypeError("Method Annotated with #[Bean] must have return type "
                . " of any Custom class (build-in types are not allowed) on Method "
                . ReflectionUtil::getFqName($ref));
        }

        $retClass = $retType->getName();
        if ($this->initMethod || $this->destroyMethod) {
            $retRefClass = RefKlass::getInstance($retClass);

            if ($this->initMethod) {
                $this->checkMethodExists($retRefClass, $this->initMethod, 'initMethod');
            }
            if ($this->destroyMethod) {
                $this->checkMethodExists($retRefClass, $this->destroyMethod, 'destroyMethod');
            }
        }


        $attributes = $ref->getAttributes();
        if (!empty($attributes)) {
            foreach ($attributes as $attribute) {
                $name = $attribute->getName();
                if ($name === Bean::class) {
                    continue;
                }
                if (is_a($name, StereoType::class, true)) {
                    throw new TypeError("Method Annotated with #[Bean] cannot have other Annotations "
                        . ReflectionUtil::getFqName($ref));
                }
            }
        }

        $this->refOwner = $ref;
        $this->returnType = $retClass;
    }

    private function checkMethodExists(RefKlass $ref, string $methodName, string $type): void {
        if (!$ref->hasMethod($methodName)) {
            throw new TypeError("Method Annotated with #[Bean] does not have "
                . "'$type' method '$methodName' defined in return class "
                . ReflectionUtil::getFqName($ref));
        }

        $method = $ref->getMethod($methodName);
        if (!$method->isPublic()) {
            throw new TypeError("Method Annotated with #[Bean]  "
                . "'$type' method '$methodName' is not defined as 'public' in return class "
                . ReflectionUtil::getFqName($ref));
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            throw new TypeError("Method Annotated with #[Bean]  "
                . "'$type' method '$methodName' must not contain required arguments "
                . ReflectionUtil::getFqName($ref));
        }
    }
}