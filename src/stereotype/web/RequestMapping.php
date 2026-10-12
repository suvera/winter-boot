<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\stereotype\util\UriPath;
use dev\winterframework\stereotype\util\UriPathPart;
use dev\winterframework\type\TypeAssert;
use ReflectionParameter;
use Throwable;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RequestMapping implements StereoType {

    /**
     * @var UriPath[]
     */
    private array $uriPaths = [];

    /**
     * @var PathVariable[]
     */
    private array $allowedPathVariables = [];

    private RefKlass|RefMethod $refOwner;

    private string $beanName = '';
    private string $beanClass = '';

    /**
     * @var RequestParam[]
     */
    private array $requestParams = [];
    private array $injectableParams = [];

    private RequestBody $requestBody;

    private bool $isRestController = false;

    private string $id;

    public string|array $path = '';
    public array $method = [];
    public ?string $name = null;
    public ?array $consumes = null;
    public ?array $produces = null;


    public function __construct(
        string|array $path = '',
        array $method = [],
        ?string $name = null,
        ?array $consumes = null,
        ?array $produces = null
    ) {
        $this->path = $path;
        $this->method = $method;
        $this->name = $name;
        $this->consumes = $consumes;
        $this->produces = $produces;
    }

    public function init(object $ref): void {
        /** @var RefKlass|RefMethod $ref */
        TypeAssert::typeOf($ref, RefKlass::class, RefMethod::class);
        $this->refOwner = $ref;

        $this->parseMethods();

        $this->isRestController = !($this->refOwner instanceof RefMethod);
        $this->id = ReflectionUtil::getFqName($this->refOwner);

        $paths = $this->getUniquePaths($this->path);

        /**
         * Append Class Level PATH too ...
         */
        if (!$this->isRestController) {
            $classReqMap = $ref->getDeclaringClass()->getAttributes(RequestMapping::class);
            if (!empty($classReqMap)) {
                /** @var RequestMapping $parentMap */
                $parentMap = $classReqMap[0]->newInstance();
                if (!empty($parentMap->path)) {
                    $paths = self::prefixClassPaths($this->getUniquePaths($parentMap->path), $paths);
                }
            }
        }

        foreach ($paths as $path) {
            $this->parsePath($path);
        }
    }

    /**
     * Every class path prefixes every method path: class ['a', 'b'] with
     * method 'x' maps 'a/x' and 'b/x' (it used to chain them into 'b/a/x').
     * An empty class path maps the method path unprefixed.
     *
     * @param string[] $classPaths
     * @param string[] $paths
     * @return string[]
     */
    public static function prefixClassPaths(array $classPaths, array $paths): array {
        $prefixed = [];
        foreach ($classPaths as $classPath) {
            $classPath = trim($classPath, '/');
            foreach ($paths as $path) {
                $prefixed[] = $classPath === '' ? $path : $classPath . '/' . ltrim($path, '/');
            }
        }
        return array_values(array_unique($prefixed));
    }

    private function getUniquePaths(string|array $path): array {
        if (!is_array($path)) {
            $paths = [$path];
        } else {
            $paths = [];
            foreach ($path as $p) {
                TypeAssert::string($p);
                $paths[] = $p;
            }
        }

        return array_unique($paths);
    }

    private function parseMethods(): void {

        $allowed = RequestMethod::getAll();
        if (empty($this->method)) {
            $this->method = $allowed;
        } else {
            $methods = [];
            foreach ($this->method as $method) {
                TypeAssert::string($method);
                $methodU = strtoupper($method);

                if (!isset($allowed[$methodU])) {
                    throw new InvalidSyntaxException('Unknown request method "' . $method
                        . '" value defined, allowed values are [' . implode(', ', $allowed)
                        . '] at ' . ReflectionUtil::getFqName($this->refOwner)
                    );
                }
                $methods[$methodU] = $methodU;
            }

            $this->method = $methods;
        }
    }

    /**
     * @return UriPath[]
     */
    public function getUriPaths(): array {
        return $this->uriPaths;
    }

    /**
     * @return PathVariable[]
     */
    public function getAllowedPathVariables(): array {
        return $this->allowedPathVariables;
    }

    public function getRequestBody(): ?RequestBody {
        return $this->requestBody ?? null;
    }

    public function isRestController(): bool {
        return $this->isRestController;
    }

    public function getId(): string {
        return $this->id;
    }

    /**
     * @return RefParameter[]
     */
    public function getInjectableParams(): array {
        return $this->injectableParams;
    }

    /**
     * @return RequestParam[]
     */
    public function getRequestParams(): array {
        return $this->requestParams;
    }

    public function getBeanName(): string {
        return $this->beanName;
    }

    public function setBeanName(string $beanName): void {
        $this->beanName = $beanName;
    }

    public function getBeanClass(): string {
        return $this->beanClass;
    }

    public function setBeanClass(string $beanClass): void {
        $this->beanClass = $beanClass;
    }

    public function getRefOwner(): RefMethod|RefKlass {
        return $this->refOwner;
    }

    private function parsePath(string $path): void {
        $path = trim($path, '/');
        $parts = explode('/', $path);

        // Parts stay in URI order as a list: keyed by text, a variable named
        // like a literal segment ("staff/{staff}") or a repeated literal
        // ("a/x/b/x") overwrote the earlier part and broke the route.
        /** @var UriPathPart[] $uriParts */
        $uriParts = [];
        /** @var array<string, UriPathPart> $pathVariables name => part */
        $pathVariables = [];
        foreach ($parts as $part) {
            $p = new UriPathPart($part);
            $uriParts[] = $p;
            if ($p->isPathVariable()) {
                if (isset($pathVariables[$p->part])) {
                    throw new InvalidSyntaxException(
                        'URI Path ' . json_encode($path) . ' repeats the PathVariable ' . $p->part
                        . ' at ' . ReflectionUtil::getFqName($this->refOwner)
                    );
                }
                $pathVariables[$p->part] = $p;
            }
        }

        if (!$this->isRestController) {
            $this->parseMethodPath($pathVariables, $this->refOwner);
        } else {
            $this->parseClassPath($uriParts, $this->refOwner);
        }

        $this->uriPaths[$path] = UriPath::ofArray($uriParts);
    }

    private function parseClassPath(array $uriParts, RefKlass $ref): void {
        /** @var UriPathPart[] $uriParts */

        foreach ($uriParts as $uriPart) {
            if ($uriPart->isPathVariable()) {
                throw new InvalidSyntaxException(
                    'PathVariable is not allowed in Path defined at Class level ' . $ref->getName()
                );
            }
        }
    }

    /** @param array<string, UriPathPart> $pathVariables */
    private function parseMethodPath(array $pathVariables, RefMethod $ref): void {

        if (!$ref->isPublic() || $ref->isStatic() || $ref->isAbstract()) {
            throw new InvalidSyntaxException(
                'Scope of Method ' . $ref->getName()
                . ' must be  public '
            );
        }

        $class = $ref->getDeclaringClass();
        $attrs = $class->getAttributes(RestController::class);
        if (count($attrs) == 0) {
            throw new InvalidSyntaxException(
                'Attribute [RequestMapping] is not allowed for method ' . $ref->getName()
                . ' as it\'s class is not annotated with RestController '
            );
        }

        $params = $ref->getParameters();

        $this->parseMethodPathVariables($params, $pathVariables, $ref);
        $this->parseMethodRequestParams($params, $ref);
    }

    /** @param array<string, UriPathPart> $pathVariables name => part */
    private function parseMethodPathVariables(array $params, array $pathVariables, RefMethod $ref): void {
        /** @var ReflectionParameter[] $params */

        $pathVars = [];
        foreach ($params as $paramA) {
            $param = RefParameter::getInstance($paramA);

            $attrs = $param->getAttributes(PathVariable::class);
            if (count($attrs) == 0) {
                continue;
            }

            $tmp = $param->getAttributes(RequestParam::class);
            if (count($tmp) > 0) {
                throw new InvalidSyntaxException(
                    'Argument "' . $param->getName() . '" cannot be annotated as PathVariable '
                    . 'and RequestParam at same time '
                    . 'in the method ' . $ref->getName()
                );
            }

            try {
                /** @var PathVariable $attr */
                $attr = $attrs[0]->newInstance();
                $attr->init($param);
            } catch (Throwable $e) {
                throw new InvalidSyntaxException(
                    'Argument "' . $param->getName() . '" defined in method ' . $ref->getName()
                    . ' has wrong Attribute definition.', 0, $e
                );
            }

            if (!isset($pathVariables[$attr->name])) {
                throw new InvalidSyntaxException(
                    'Argument "' . $param->getName() . '" is defined as PathVariable '
                    . ' but not found in the URI definition of the method ' . $ref->getName()
                );
            }

            $pathVars[$attr->name] = $attr;
            $pathVariables[$attr->name]->type = $attr->getVariableType();
        }

        foreach ($pathVariables as $uriPart) {
            if (!$uriPart->isPathVariable()) {
                continue;
            }
            if (isset($pathVars[$uriPart->part])) {
                $this->allowedPathVariables[$uriPart->part] = $pathVars[$uriPart->part];
            } else {
                throw new InvalidSyntaxException(
                    'URI Path  ' . json_encode($this->path) . ' defined in method ' . $ref->getName()
                    . ' has Undefined PathVariable ' . $uriPart->part
                );
            }
        }
    }

    private function parseMethodRequestParams(array $params, RefMethod $ref): void {
        /** @var UriPathPart[] $uriParts */
        /** @var ReflectionParameter[] $params */

        foreach ($params as $paramA) {
            $param = RefParameter::getInstance($paramA);
            $attrs = $param->getAttributes(RequestBody::class);
            if (count($attrs) > 0) {
                if (isset($this->requestBody)) {
                    throw new InvalidSyntaxException(
                        'Argument ' . $param->getName() . ' defined in method ' . $ref->getName()
                        . ' has Multiple Attribute definitions (RequestBody).'
                    );
                }
                try {
                    /** @var RequestBody $attr */
                    $attr = $attrs[0]->newInstance();
                    $this->requestBody = $attr;
                    $this->requestBody->init($param);
                } catch (Throwable $e) {
                    throw new InvalidSyntaxException(
                        'Argument "' . $param->getName() . '" defined in method ' . $ref->getName()
                        . ' has wrong Attribute definition (RequestBody).', 0, $e
                    );
                }
                continue;
            }

            $attrs = $param->getAttributes(RequestParam::class);
            if (count($attrs) == 0) {
                $this->injectableParams[] = RefParameter::getInstance($param);
                continue;
            }

            try {
                /** @var RequestParam $attr */
                $attr = $attrs[0]->newInstance();
                $attr->init($param);
            } catch (Throwable $e) {
                throw new InvalidSyntaxException(
                    'Argument "' . $param->getName() . '" defined in method ' . $ref->getName()
                    . ' has wrong Attribute definition (RequestParam).' . $e->getMessage(), 0, $e
                );
            }

            $this->requestParams[] = $attr;
        }
    }

}