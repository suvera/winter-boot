<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\schema\PhpDocTypeParser;
use dev\winterframework\stereotype\mcp\McpPrompt;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\web\http\HttpRequest;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * One derived #[McpPrompt]: its arguments and how prompts/get calls it.
 */
final class McpPromptDefinition {

    /**
     * @param array<int, array{name: string, description: string, required: bool}> $arguments
     * @param array<int, array{bind: string, param: string}> $bindings bind = "arg" or "httpRequest"
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $title,
        public readonly string $description,
        public readonly array $arguments,
        public readonly string $className,
        public readonly string $methodName,
        public readonly array $bindings,
    ) {
    }

    public static function create(ReflectionMethod $method, McpPrompt $attr): self {
        $class = $method->getDeclaringClass();
        $where = '#[McpPrompt] ' . $class->getName() . '::' . $method->getName() . '(): ';
        $doc = $method->getDocComment();
        $docParams = PhpDocTypeParser::params($doc);

        $arguments = [];
        $bindings = [];
        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
            if ($typeName === HttpRequest::class) {
                $bindings[] = ['bind' => 'httpRequest', 'param' => $p->getName()];
                continue;
            }
            if ($typeName !== 'string') {
                throw new McpDefinitionException($where . 'parameter $' . $p->getName()
                    . ' must be a string: MCP prompt arguments are strings');
            }
            $arguments[] = [
                'name' => $p->getName(),
                'description' => $docParams[$p->getName()]['description'] ?? '',
                'required' => !$p->isDefaultValueAvailable() && !$type->allowsNull(),
            ];
            $bindings[] = ['bind' => 'arg', 'param' => $p->getName()];
        }

        $description = trim($attr->description) !== '' ? trim($attr->description) : PhpDocTypeParser::summary($doc);
        $name = $attr->name ?? McpToolDefinitionFactory::deriveName($class->getShortName(), $method->getName());
        if (!preg_match(McpTool::NAME_REGEX, $name)) {
            throw new McpDefinitionException($where . 'prompt name "' . $name . '" must match ' . McpTool::NAME_REGEX);
        }

        return new self(
            name: $name,
            title: $attr->title,
            description: $description,
            arguments: $arguments,
            className: $class->getName(),
            methodName: $method->getName(),
            bindings: $bindings,
        );
    }

    /** prompts/list entry. */
    public function toListEntry(): array {
        $entry = ['name' => $this->name];
        if ($this->title !== null) {
            $entry['title'] = $this->title;
        }
        if ($this->description !== '') {
            $entry['description'] = $this->description;
        }
        $entry['arguments'] = array_map(function (array $a) {
            $out = ['name' => $a['name']];
            if ($a['description'] !== '') {
                $out['description'] = $a['description'];
            }
            $out['required'] = $a['required'];
            return $out;
        }, $this->arguments);
        return $entry;
    }
}
