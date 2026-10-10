<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\stereotype\web\RequestMapping;

/**
 * One derived MCP tool: what tools/list advertises and how tools/call
 * binds arguments and shapes the result. Built once at boot by
 * McpToolDefinitionFactory; immutable afterwards.
 */
final class McpToolDefinition {

    public const KIND_REST = 'rest';
    public const KIND_SERVICE = 'service';

    /** structuredContent is the result object itself. */
    public const WRAP_OBJECT = 'object';
    /** A list result, sent as {"items": [...]}. */
    public const WRAP_ITEMS = 'items';
    /** A scalar / nullable result, sent as {"result": value}. */
    public const WRAP_RESULT = 'result';
    /** No structuredContent (no output schema). */
    public const WRAP_NONE = 'none';

    /** Argument bound into the URI template. */
    public const BIND_PATH = 'path';
    /** Argument sent as a query parameter. */
    public const BIND_QUERY = 'query';
    /** Argument sent as a form (post) parameter. */
    public const BIND_POST = 'post';
    /** Argument sent as the request body. */
    public const BIND_BODY = 'body';
    /** Service tool argument, bound by name. */
    public const BIND_ARG = 'arg';
    /** Service tool parameter injected with the /mcp HttpRequest. */
    public const BIND_HTTP_REQUEST = 'httpRequest';
    /** Service tool parameter injected with the McpToolContext. */
    public const BIND_CONTEXT = 'context';

    /**
     * @param array<int, array{arg: string, bind: string, param: string}> $bindings in method parameter order
     * @param array<string, bool|string> $annotations tools/list annotations
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly ?array $outputSchema,
        public readonly string $outputWrap,
        public readonly array $annotations,
        public readonly bool $readOnly,
        public readonly string $kind,
        public readonly string $className,
        public readonly string $methodName,
        public readonly array $bindings,
        public readonly ?string $httpMethod = null,
        public readonly ?RequestMapping $mapping = null,
        public readonly ?string $routeTemplate = null,
    ) {
    }

    public function isRest(): bool {
        return $this->kind === self::KIND_REST;
    }

    /** The tools/list entry. */
    public function toListEntry(): array {
        $entry = ['name' => $this->name];
        if ($this->title !== null) {
            $entry['title'] = $this->title;
        }
        $entry['description'] = $this->description;
        $entry['inputSchema'] = $this->inputSchema;
        if ($this->outputSchema !== null) {
            $entry['outputSchema'] = $this->outputSchema;
        }
        $entry['annotations'] = $this->annotations;
        return $entry;
    }
}
