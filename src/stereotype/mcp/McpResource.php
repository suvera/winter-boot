<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\mcp;

use Attribute;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

/**
 * Serves a #[Service] / #[Component] method as an MCP resource that
 * clients read with resources/read.
 *
 * - A plain URI ("config://app") is one fixed resource, listed in
 *   resources/list.
 * - A URI with {placeholders} ("site://{domain}/summary/{range}") is a
 *   resource template, listed in resources/templates/list; each
 *   placeholder is passed to the method parameter of the same name.
 *   A $listMethod on the same bean can name the concrete resources a
 *   caller may read, for resources/list.
 *
 * The method returns the content: a string, or an array / object sent as
 * JSON. Throw McpResourceNotFoundException for a URI that doesn't exist.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpResource implements StereoType {

    /**
     * @param string $uri fixed URI, or a URI template with {name} placeholders
     * @param string|null $name resource name; default derived from class and method
     * @param string|null $title human-readable label
     * @param string $description what the resource contains, for the model
     * @param string|null $mimeType default: text/plain for string returns, else application/json
     * @param string|null $listMethod public method on the same bean returning the concrete
     *        resources for resources/list: a list of ['uri' => ..., 'name' => ..., ...] arrays
     */
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public ?string $title = null,
        public string $description = '',
        public ?string $mimeType = null,
        public ?string $listMethod = null,
    ) {
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
        $where = '#[McpResource] ' . ReflectionUtil::getFqName($ref) . '(): ';
        McpTool::checkServiceMethod($ref, $where);
        if (trim($this->uri) === '' || !preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $this->uri)) {
            throw new McpDefinitionException($where . 'uri must be an absolute URI such as "config://app"');
        }
        if ($this->name !== null && !preg_match(McpTool::NAME_REGEX, $this->name)) {
            throw new McpDefinitionException($where . 'name must match ' . McpTool::NAME_REGEX);
        }
    }
}
