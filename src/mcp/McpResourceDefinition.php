<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\schema\PhpDocTypeParser;
use dev\winterframework\stereotype\mcp\McpResource;
use dev\winterframework\web\http\HttpRequest;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * One derived #[McpResource]: a fixed resource or a URI template, and how
 * resources/read calls its method.
 */
final class McpResourceDefinition {

    /**
     * @param array<int, array{bind: string, param: string}> $bindings in parameter order;
     *        bind = "var" (a URI placeholder named like the parameter) or "httpRequest"
     */
    public function __construct(
        public readonly McpUriTemplate $uri,
        public readonly string $name,
        public readonly ?string $title,
        public readonly string $description,
        public readonly string $mimeType,
        public readonly string $className,
        public readonly string $methodName,
        public readonly array $bindings,
        public readonly ?string $listMethod,
    ) {
    }

    public static function create(ReflectionMethod $method, McpResource $attr): self {
        $class = $method->getDeclaringClass();
        $where = '#[McpResource] ' . $class->getName() . '::' . $method->getName() . '(): ';
        $uri = new McpUriTemplate($attr->uri);
        $vars = $uri->getVariables();
        if (count($vars) !== count(array_unique($vars))) {
            throw new McpDefinitionException($where . 'a placeholder appears twice in "' . $attr->uri . '"');
        }

        $bindings = [];
        $bound = [];
        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
            if ($typeName === HttpRequest::class) {
                $bindings[] = ['bind' => 'httpRequest', 'param' => $p->getName()];
                continue;
            }
            if (!in_array($p->getName(), $vars, true)) {
                throw new McpDefinitionException($where . 'parameter $' . $p->getName()
                    . ' is not a placeholder of "' . $attr->uri . '"; name it {' . $p->getName() . '}');
            }
            if (!in_array($typeName, ['', 'string', 'int', 'float', 'bool', 'mixed'], true)) {
                throw new McpDefinitionException($where . 'placeholder parameter $' . $p->getName()
                    . ' must be string, int, float or bool');
            }
            $bindings[] = ['bind' => 'var', 'param' => $p->getName()];
            $bound[] = $p->getName();
        }
        $missing = array_diff($vars, $bound);
        if ($missing) {
            throw new McpDefinitionException($where . 'placeholder {' . implode('}, {', $missing)
                . '} has no method parameter of the same name');
        }

        if ($attr->listMethod !== null) {
            if (!$class->hasMethod($attr->listMethod) || !$class->getMethod($attr->listMethod)->isPublic()
                || $class->getMethod($attr->listMethod)->isStatic()) {
                throw new McpDefinitionException($where . 'listMethod "' . $attr->listMethod
                    . '" must be a public instance method of ' . $class->getName());
            }
            foreach ($class->getMethod($attr->listMethod)->getParameters() as $p) {
                $t = $p->getType();
                $isRequest = $t instanceof ReflectionNamedType && $t->getName() === HttpRequest::class;
                if (!$isRequest && !$p->isDefaultValueAvailable()) {
                    throw new McpDefinitionException($where . 'listMethod "' . $attr->listMethod
                        . '" may only take an HttpRequest parameter');
                }
            }
        }

        $description = trim($attr->description) !== ''
            ? trim($attr->description)
            : PhpDocTypeParser::summary($method->getDocComment());

        $return = $method->getReturnType();
        $mime = $attr->mimeType ?? (($return instanceof ReflectionNamedType && $return->getName() === 'string')
            ? 'text/plain' : 'application/json');

        return new self(
            uri: $uri,
            name: $attr->name ?? McpToolDefinitionFactory::deriveName($class->getShortName(), $method->getName()),
            title: $attr->title,
            description: $description,
            mimeType: $mime,
            className: $class->getName(),
            methodName: $method->getName(),
            bindings: $bindings,
            listMethod: $attr->listMethod,
        );
    }

    public function isTemplate(): bool {
        return $this->uri->isTemplate();
    }

    /** resources/list entry of a fixed resource, or resources/templates/list entry of a template. */
    public function toListEntry(): array {
        $entry = $this->isTemplate()
            ? ['uriTemplate' => $this->uri->template]
            : ['uri' => $this->uri->template];
        $entry['name'] = $this->name;
        if ($this->title !== null) {
            $entry['title'] = $this->title;
        }
        if ($this->description !== '') {
            $entry['description'] = $this->description;
        }
        $entry['mimeType'] = $this->mimeType;
        return $entry;
    }
}
