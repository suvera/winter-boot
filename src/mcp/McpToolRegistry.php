<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\mcp\McpPrompt;
use dev\winterframework\stereotype\mcp\McpResource;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\web\RequestMapping;
use ReflectionMethod;

/**
 * Every #[McpTool], #[McpResource] and #[McpPrompt] of the application,
 * derived once at boot. Tool, prompt and resource names and resource URIs
 * are unique; a clash is a boot error naming both methods.
 */
class McpToolRegistry {

    /** @var array<string, McpToolDefinition> name => tool, in discovery order */
    private array $tools = [];

    /** @var array<string, McpResourceDefinition> name => resource */
    private array $resources = [];

    /** @var array<string, McpPromptDefinition> name => prompt */
    private array $prompts = [];

    /** @var string[] McpToolInterceptor bean classes, sorted */
    private array $interceptorClasses = [];

    /** @var McpToolInterceptor[]|null resolved lazily (beans may not exist yet at boot) */
    private ?array $interceptors = null;

    public static function fromResources(ClassResources $resources): self {
        $registry = new self();
        foreach (self::methodsWith($resources, McpResource::class) as [$ref, $attr]) {
            $registry->addResource(McpResourceDefinition::create($ref, $attr));
        }
        foreach (self::methodsWith($resources, McpPrompt::class) as [$ref, $attr]) {
            $registry->addPrompt(McpPromptDefinition::create($ref, $attr));
        }
        $seen = [];
        foreach ($resources->getClassesByAttribute(McpTool::class) as $class) {
            /** @var ClassResource $class */
            $className = $class->getClass()->getName();
            if (isset($seen[$className])) {
                continue;
            }
            $seen[$className] = true;
            foreach ($class->getMethods() as $method) {
                /** @var MethodResource $method */
                $tool = $method->getAttribute(McpTool::class);
                if (!$tool instanceof McpTool) {
                    continue;
                }
                $mapping = null;
                foreach ($method->getAttributes() as $attr) {
                    if ($attr instanceof RequestMapping) {
                        $mapping = $attr;
                        break;
                    }
                }
                $ref = new ReflectionMethod($className, $method->getMethod()->getShortName());
                $registry->add(McpToolDefinitionFactory::create($ref, $tool, $mapping));
            }
        }

        $interceptors = [];
        foreach ($resources as $class) {
            /** @var ClassResource $class */
            $name = $class->getClass()->getName();
            $isBean = $class->getAttribute(Component::class) !== null
                || $class->getAttribute(Service::class) !== null
                || $class->getAttribute(Configuration::class) !== null;
            if ($isBean && is_a($name, McpToolInterceptor::class, true)) {
                $interceptors[] = $name;
            }
        }
        sort($interceptors);
        $registry->interceptorClasses = $interceptors;
        return $registry;
    }

    /**
     * @return array<int, array{0: ReflectionMethod, 1: object}> methods carrying $attribute, with the attribute
     */
    private static function methodsWith(ClassResources $resources, string $attribute): array {
        $out = [];
        $seen = [];
        foreach ($resources->getClassesByAttribute($attribute) as $class) {
            /** @var ClassResource $class */
            $className = $class->getClass()->getName();
            if (isset($seen[$className])) {
                continue;
            }
            $seen[$className] = true;
            foreach ($class->getMethods() as $method) {
                /** @var MethodResource $method */
                $attr = $method->getAttribute($attribute);
                if ($attr !== null) {
                    $out[] = [new ReflectionMethod($className, $method->getMethod()->getShortName()), $attr];
                }
            }
        }
        return $out;
    }

    public function addResource(McpResourceDefinition $resource): void {
        foreach ($this->resources as $other) {
            if ($other->name === $resource->name || $other->uri->template === $resource->uri->template) {
                throw new McpDefinitionException('#[McpResource] "' . $resource->uri->template . '" ('
                    . $resource->name . ') clashes with ' . $other->className . '::' . $other->methodName
                    . '() by name or URI; set a different name: or uri:');
            }
        }
        $this->resources[$resource->name] = $resource;
    }

    public function addPrompt(McpPromptDefinition $prompt): void {
        if (isset($this->prompts[$prompt->name])) {
            $other = $this->prompts[$prompt->name];
            throw new McpDefinitionException('#[McpPrompt] name "' . $prompt->name . '" is used by both '
                . $other->className . '::' . $other->methodName . '() and '
                . $prompt->className . '::' . $prompt->methodName . '(); set name: on one of them');
        }
        $this->prompts[$prompt->name] = $prompt;
    }

    /** @return array<string, McpResourceDefinition> */
    public function resources(): array {
        return $this->resources;
    }

    /** @return array<string, McpPromptDefinition> */
    public function prompts(): array {
        return $this->prompts;
    }

    public function findPrompt(string $name): ?McpPromptDefinition {
        return $this->prompts[$name] ?? null;
    }

    /**
     * The resource serving $uri and its placeholder values: a fixed URI
     * first, then templates in registration order.
     * @return array{0: McpResourceDefinition, 1: array<string, string>}|null
     */
    public function findResource(string $uri): ?array {
        foreach ($this->resources as $r) {
            if (!$r->isTemplate() && $r->uri->template === $uri) {
                return [$r, []];
            }
        }
        foreach ($this->resources as $r) {
            if ($r->isTemplate() && ($vars = $r->uri->match($uri)) !== null) {
                return [$r, $vars];
            }
        }
        return null;
    }

    public function add(McpToolDefinition $tool): void {
        if (isset($this->tools[$tool->name])) {
            $other = $this->tools[$tool->name];
            throw new McpDefinitionException('#[McpTool] name "' . $tool->name . '" is used by both '
                . $other->className . '::' . $other->methodName . '() and '
                . $tool->className . '::' . $tool->methodName . '(); set name: on one of them');
        }
        $this->tools[$tool->name] = $tool;
    }

    /** True when the application declares no tools, resources or prompts. */
    public function isEmpty(): bool {
        return $this->tools === [] && $this->resources === [] && $this->prompts === [];
    }

    /** @return array<string, McpToolDefinition> */
    public function all(): array {
        return $this->tools;
    }

    public function find(string $name): ?McpToolDefinition {
        return $this->tools[$name] ?? null;
    }

    /** @return string[] */
    public function getInterceptorClasses(): array {
        return $this->interceptorClasses;
    }

    /** @return McpToolInterceptor[] */
    public function interceptors(?ApplicationContext $ctx): array {
        if ($this->interceptors === null) {
            if ($ctx === null) {
                return [];
            }
            $list = [];
            foreach ($this->interceptorClasses as $cls) {
                $bean = $ctx->beanByClass($cls);
                if ($bean instanceof McpToolInterceptor) {
                    $list[] = $bean;
                }
            }
            $this->interceptors = $list;
        }
        return $this->interceptors;
    }

    /** For tests and programmatic setups. */
    public function setInterceptors(array $interceptors): void {
        $this->interceptors = array_values($interceptors);
    }
}
