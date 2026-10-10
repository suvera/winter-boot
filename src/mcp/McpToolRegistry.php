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
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\web\RequestMapping;
use ReflectionMethod;

/**
 * Every #[McpTool] of the application, derived once at boot. Tool names
 * are unique; a clash is a boot error naming both methods.
 */
class McpToolRegistry {

    /** @var array<string, McpToolDefinition> name => tool, in discovery order */
    private array $tools = [];

    /** @var string[] McpToolInterceptor bean classes, sorted */
    private array $interceptorClasses = [];

    /** @var McpToolInterceptor[]|null resolved lazily (beans may not exist yet at boot) */
    private ?array $interceptors = null;

    public static function fromResources(ClassResources $resources): self {
        $registry = new self();
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

    public function add(McpToolDefinition $tool): void {
        if (isset($this->tools[$tool->name])) {
            $other = $this->tools[$tool->name];
            throw new McpDefinitionException('#[McpTool] name "' . $tool->name . '" is used by both '
                . $other->className . '::' . $other->methodName . '() and '
                . $tool->className . '::' . $tool->methodName . '(); set name: on one of them');
        }
        $this->tools[$tool->name] = $tool;
    }

    public function isEmpty(): bool {
        return $this->tools === [];
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
