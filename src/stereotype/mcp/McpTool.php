<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\mcp;

use Attribute;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

/**
 * Exposes a method as a Model Context Protocol tool on the built-in
 * MCP endpoint (POST <context-path>/mcp).
 *
 * Allowed on request-mapped #[RestController] methods (called through
 * the normal REST pipeline) and on public #[Service] / #[Component]
 * methods (called on the bean). Everything left out is derived from
 * the signature, PHPDoc and HTTP method; see the MCP docs page.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpTool implements StereoType {

    public const NAME_REGEX = '/^[A-Za-z0-9_.\-]{1,128}$/';

    private RefMethod $refOwner;

    /**
     * @param string $description What the tool does, for the model. Defaults to the PHPDoc summary.
     * @param string|null $name Tool name; default "<class prefix>_<method>" in snake_case.
     * @param string|null $title Human-readable label shown by clients.
     * @param array|null $inputSchema Replaces the derived input schema (top level must be an object).
     * @param array|null $outputSchema Replaces the derived output schema (top level must be an object).
     * @param string|null $outputType DTO class the result really is, for ResponseEntity/array returns.
     * @param string|null $httpMethod HTTP method to call, required for multi-method mappings.
     * @param bool|null $readOnly readOnlyHint; derived from the HTTP method when null.
     * @param bool|null $destructive destructiveHint; derived when null.
     * @param bool|null $idempotent idempotentHint; derived when null.
     * @param bool|null $openWorld openWorldHint; not sent when null.
     */
    public function __construct(
        public string $description = '',
        public ?string $name = null,
        public ?string $title = null,
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public ?string $outputType = null,
        public ?string $httpMethod = null,
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
        public ?bool $openWorld = null,
    ) {
    }

    public function getRefOwner(): RefMethod {
        return $this->refOwner;
    }

    /**
     * Resources and prompts live on #[Service] / #[Component] methods:
     * public, non-static, non-abstract.
     */
    public static function checkServiceMethod(RefMethod $ref, string $where): void {
        if (!$ref->isPublic() || $ref->isStatic() || $ref->isAbstract()
            || $ref->isConstructor() || $ref->isDestructor()) {
            throw new McpDefinitionException($where . 'must be a public, non-static, non-abstract method');
        }
        $class = $ref->getDeclaringClass();
        if (count($class->getAttributes(Service::class)) === 0
            && count($class->getAttributes(Component::class)) === 0) {
            throw new McpDefinitionException($where . 'its class must be a #[Service] or #[Component]');
        }
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
        $this->refOwner = $ref;
        $where = '#[McpTool] ' . ReflectionUtil::getFqName($ref) . '(): ';

        if (!$ref->isPublic() || $ref->isStatic() || $ref->isAbstract()
            || $ref->isConstructor() || $ref->isDestructor()) {
            throw new McpDefinitionException($where . 'must be a public, non-static, non-abstract method');
        }

        $class = $ref->getDeclaringClass();
        $isStereo = false;
        foreach ([RestController::class, Service::class, Component::class] as $stereo) {
            if (count($class->getAttributes($stereo)) > 0) {
                $isStereo = true;
                break;
            }
        }
        if (!$isStereo) {
            throw new McpDefinitionException($where
                . 'its class must be a #[RestController], #[Service] or #[Component]');
        }

        if ($this->name !== null && !preg_match(self::NAME_REGEX, $this->name)) {
            throw new McpDefinitionException($where . 'name must match ' . self::NAME_REGEX);
        }

        if ($this->outputSchema !== null && $this->outputType !== null) {
            throw new McpDefinitionException($where . 'set outputSchema or outputType, not both');
        }
        foreach (['inputSchema' => $this->inputSchema, 'outputSchema' => $this->outputSchema] as $key => $schema) {
            if ($schema !== null && ($schema['type'] ?? null) !== 'object') {
                throw new McpDefinitionException($where . $key . ' must have "type": "object" at the top level');
            }
        }

        if ($this->readOnly === true && ($this->destructive === true || $this->idempotent === false)) {
            throw new McpDefinitionException($where
                . 'readOnly: true contradicts destructive: true / idempotent: false');
        }
    }
}
