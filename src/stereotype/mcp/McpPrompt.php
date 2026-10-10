<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\mcp;

use Attribute;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

/**
 * Serves a #[Service] / #[Component] method as an MCP prompt (a reusable
 * message template clients offer to users, e.g. as a slash command).
 *
 * The prompt's arguments are the method's string parameters: required
 * unless they have a default or are nullable; described by their @param
 * text. The method returns the text of one user message, or a list of
 * messages: ['role' => 'user'|'assistant', 'text' => '...'].
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpPrompt implements StereoType {

    /**
     * @param string $description what the prompt does; default: the PHPDoc summary
     * @param string|null $name prompt name; default derived from class and method
     * @param string|null $title human-readable label
     */
    public function __construct(
        public string $description = '',
        public ?string $name = null,
        public ?string $title = null,
    ) {
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
        $where = '#[McpPrompt] ' . ReflectionUtil::getFqName($ref) . '(): ';
        McpTool::checkServiceMethod($ref, $where);
        if ($this->name !== null && !preg_match(McpTool::NAME_REGEX, $this->name)) {
            throw new McpDefinitionException($where . 'name must match ' . McpTool::NAME_REGEX);
        }
    }
}
