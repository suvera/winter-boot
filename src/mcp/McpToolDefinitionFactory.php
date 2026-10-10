<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\exception\McpSchemaException;
use dev\winterframework\mcp\schema\McpSchemaDeriver;
use dev\winterframework\mcp\schema\PhpDocTypeParser;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Derives a McpToolDefinition from a #[McpTool] method: name, hints,
 * argument bindings, input and output schemas. Pure and static, so the
 * rules are unit-testable without booting the server. Every problem is
 * a McpDefinitionException that names the method.
 */
final class McpToolDefinitionFactory {

    public static function create(ReflectionMethod $method, McpTool $tool, ?RequestMapping $mapping): McpToolDefinition {
        $class = $method->getDeclaringClass();
        $where = '#[McpTool] ' . $class->getName() . '::' . $method->getName() . '(): ';
        $isRest = count($class->getAttributes(RestController::class)) > 0;

        if ($isRest && $mapping === null) {
            throw new McpDefinitionException($where . 'a #[RestController] method needs a request mapping '
                . '(#[GetMapping], #[PostMapping], ...); move non-endpoint tools to a #[Service]');
        }

        $doc = $method->getDocComment();
        $description = trim($tool->description) !== '' ? trim($tool->description) : PhpDocTypeParser::summary($doc);
        if ($description === '') {
            throw new McpDefinitionException($where . 'needs a description: set #[McpTool(description: ...)] '
                . 'or write a PHPDoc summary');
        }

        $name = $tool->name ?? self::deriveName($class->getShortName(), $method->getName());
        if (!preg_match(McpTool::NAME_REGEX, $name)) {
            throw new McpDefinitionException($where . 'tool name "' . $name . '" must match ' . McpTool::NAME_REGEX
                . '; set name: explicitly');
        }

        $httpMethod = $isRest ? self::resolveHttpMethod($tool, $mapping, $where) : null;
        if (!$isRest && $tool->httpMethod !== null) {
            throw new McpDefinitionException($where . 'httpMethod applies to REST endpoints only');
        }

        [$readOnly, $annotations] = self::hints($tool, $httpMethod);

        $docParams = PhpDocTypeParser::params($doc);
        // With an explicit inputSchema nothing is derived (that is what it is
        // for: inputs the signature can't describe); only names are checked.
        $derive = $tool->inputSchema === null;
        [$bindings, $inputSchema] = $isRest
            ? self::restInput($method, $mapping, $docParams, $where, $derive)
            : self::serviceInput($method, $docParams, $where, $derive);

        if ($tool->inputSchema !== null) {
            self::checkInputOverride($tool->inputSchema, $inputSchema, $where);
            $inputSchema = $tool->inputSchema;
        }

        [$outputSchema, $wrap] = self::output($method, $tool, $isRest, $where);

        $routeTemplate = null;
        if ($isRest) {
            $paths = array_keys($mapping->getUriPaths());
            $routeTemplate = (string)($paths[0] ?? '');
        }

        return new McpToolDefinition(
            name: $name,
            title: $tool->title,
            description: $description,
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            outputWrap: $wrap,
            annotations: $annotations,
            readOnly: $readOnly,
            kind: $isRest ? McpToolDefinition::KIND_REST : McpToolDefinition::KIND_SERVICE,
            className: $class->getName(),
            methodName: $method->getName(),
            bindings: $bindings,
            httpMethod: $httpMethod,
            mapping: $mapping,
            routeTemplate: $routeTemplate,
        );
    }

    /**
     * "<prefix>_<method>" in snake_case; the prefix is the class short
     * name without a trailing Controller/Service/Component.
     */
    public static function deriveName(string $classShortName, string $methodName): string {
        $prefix = (string)preg_replace('/(Controller|Service|Component)$/', '', $classShortName);
        if ($prefix === '') {
            $prefix = $classShortName;
        }
        return self::snake($prefix) . '_' . self::snake($methodName);
    }

    public static function snake(string $s): string {
        $s = (string)preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $s);
        $s = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s);
        return strtolower($s);
    }

    private static function resolveHttpMethod(McpTool $tool, RequestMapping $mapping, string $where): string {
        $methods = array_values($mapping->method);
        if ($tool->httpMethod !== null) {
            $wanted = strtoupper($tool->httpMethod);
            if (!in_array($wanted, $methods, true)) {
                throw new McpDefinitionException($where . 'httpMethod "' . $wanted . '" is not one of the mapping\'s '
                    . 'methods [' . implode(', ', $methods) . ']');
            }
            return $wanted;
        }
        if (count($methods) !== 1) {
            throw new McpDefinitionException($where . 'the mapping allows ['
                . implode(', ', $methods) . ']; set httpMethod: to the one the tool uses');
        }
        return $methods[0];
    }

    /**
     * Hints derived from the HTTP method (service tools count as POST),
     * explicit attribute values win.
     * @return array{0: bool, 1: array<string, bool|string>}
     */
    public static function hints(McpTool $tool, ?string $httpMethod): array {
        [$ro, $destructive, $idempotent] = match ($httpMethod) {
            RequestMethod::GET, RequestMethod::HEAD => [true, false, true],
            RequestMethod::PUT => [false, false, true],
            RequestMethod::DELETE => [false, true, true],
            default => [false, true, false],
        };
        $readOnly = $tool->readOnly ?? $ro;
        if (!$readOnly && $ro) {
            // A GET marked readOnly: false has no method-derived write hints.
            [$destructive, $idempotent] = [true, false];
        }
        $annotations = [];
        if ($tool->title !== null) {
            $annotations['title'] = $tool->title;
        }
        $annotations['readOnlyHint'] = $readOnly;
        if (!$readOnly) {
            $annotations['destructiveHint'] = $tool->destructive ?? $destructive;
            $annotations['idempotentHint'] = $tool->idempotent ?? $idempotent;
        }
        if ($tool->openWorld !== null) {
            $annotations['openWorldHint'] = $tool->openWorld;
        }
        return [$readOnly, $annotations];
    }

    // ------------------------------------------------------------------ input

    /** @return array{0: array, 1: array} bindings, input schema */
    private static function restInput(
        ReflectionMethod $method,
        RequestMapping $mapping,
        array $docParams,
        string $where,
        bool $derive
    ): array {
        $deriver = McpSchemaDeriver::forInput();
        $class = $method->getDeclaringClass();

        $pathVars = [];
        foreach ($mapping->getAllowedPathVariables() as $uriName => $pv) {
            /** @var PathVariable $pv */
            $pathVars[$pv->getVariableName()] = [(string)$uriName, $pv];
        }
        $requestParams = [];
        foreach ($mapping->getRequestParams() as $rp) {
            /** @var RequestParam $rp */
            $requestParams[$rp->getVariableName()] = $rp;
        }
        $body = $mapping->getRequestBody();

        $bindings = [];
        $props = [];
        $required = [];
        foreach ($method->getParameters() as $param) {
            $php = $param->getName();
            $label = 'parameter $' . $php;
            $desc = $docParams[$php]['description'] ?? '';

            if (isset($pathVars[$php])) {
                [$arg] = $pathVars[$php];
                $schema = $derive ? $deriver->valueSchema($param->getType(), null, $class, $label) : [];
                if (($schema['type'] ?? null) === 'integer') {
                    // The route only matches digits.
                    $schema['minimum'] = 0;
                }
                $bindings[] = ['arg' => $arg, 'bind' => McpToolDefinition::BIND_PATH, 'param' => $php];
                $props[$arg] = self::withDescription($schema, $desc);
                $required[] = $arg;
                continue;
            }

            if (isset($requestParams[$php])) {
                $rp = $requestParams[$php];
                if (in_array($rp->getSource(), ['header', 'cookie'], true)) {
                    // Caller identity, copied from the /mcp request; never model input.
                    continue;
                }
                $arg = $rp->name;
                try {
                    $schema = $derive ? $deriver->valueSchema($param->getType(), null, $class, $label) : [];
                } catch (McpSchemaException $e) {
                    throw new McpDefinitionException($where . $e->getMessage(), 0, $e);
                }
                if ($rp->defaultValue !== null && McpSchemaDeriver::isJsonValue($rp->defaultValue)) {
                    $schema['default'] = McpSchemaDeriver::jsonDefault($rp->defaultValue, $schema);
                }
                $bindings[] = [
                    'arg' => $arg,
                    'bind' => $rp->getSource() === 'post' ? McpToolDefinition::BIND_POST : McpToolDefinition::BIND_QUERY,
                    'param' => $php,
                ];
                $props[$arg] = self::withDescription($schema, $desc);
                if ($rp->required) {
                    $required[] = $arg;
                }
                continue;
            }

            if ($body !== null && $body->getVariableName() === $php) {
                if ($body->getVariableType() === 'string') {
                    $schema = ['type' => 'string'];
                } else {
                    $schema = $derive ? self::inputValue($deriver, $param, $docParams, $class, $where) : [];
                }
                $bindings[] = ['arg' => $php, 'bind' => McpToolDefinition::BIND_BODY, 'param' => $php];
                $props[$php] = self::withDescription($schema, $desc);
                $required[] = $php;
                continue;
            }
            // HttpRequest / ResponseEntity injectables: filled by the dispatcher.
        }

        return [$bindings, self::inputObject($props, $required, $deriver)];
    }

    /** @return array{0: array, 1: array} bindings, input schema */
    private static function serviceInput(ReflectionMethod $method, array $docParams, string $where, bool $derive): array {
        $deriver = McpSchemaDeriver::forInput();
        $class = $method->getDeclaringClass();
        $bindings = [];
        $props = [];
        $required = [];
        foreach ($method->getParameters() as $param) {
            $php = $param->getName();
            if ($param->isVariadic()) {
                throw new McpDefinitionException($where . 'variadic parameter $' . $php . ' is not supported');
            }
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
            if ($typeName === HttpRequest::class) {
                $bindings[] = ['arg' => $php, 'bind' => McpToolDefinition::BIND_HTTP_REQUEST, 'param' => $php];
                continue;
            }
            if ($typeName === McpToolContext::class) {
                $bindings[] = ['arg' => $php, 'bind' => McpToolDefinition::BIND_CONTEXT, 'param' => $php];
                continue;
            }
            if ($typeName === ResponseEntity::class) {
                throw new McpDefinitionException($where . 'a service tool has no ResponseEntity to inject ($'
                    . $php . ')');
            }

            $schema = $derive ? self::inputValue($deriver, $param, $docParams, $class, $where) : [];
            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (McpSchemaDeriver::isJsonValue($default)) {
                    $schema['default'] = McpSchemaDeriver::jsonDefault($default, $schema);
                }
            } elseif ($type === null || !$type->allowsNull()) {
                $required[] = $php;
            }
            $bindings[] = ['arg' => $php, 'bind' => McpToolDefinition::BIND_ARG, 'param' => $php];
            $props[$php] = self::withDescription($schema, $docParams[$php]['description'] ?? '');
        }
        return [$bindings, self::inputObject($props, $required, $deriver)];
    }

    private static function inputValue(
        McpSchemaDeriver $deriver,
        ReflectionParameter $param,
        array $docParams,
        ReflectionClass $class,
        string $where
    ): array {
        $php = $param->getName();
        try {
            return $deriver->valueSchema(
                $param->getType(),
                $docParams[$php]['type'] ?? null,
                $class,
                'parameter $' . $php
            );
        } catch (McpSchemaException $e) {
            throw new McpDefinitionException($where . $e->getMessage() . '. Describe it with one of:'
                . "\n  - a PHPDoc type:      @param array<string, string> \$$php"
                . "\n  - a DTO class:        SomeDto \$$php"
                . "\n  - an explicit schema: #[McpTool(inputSchema: [...])]", 0, $e);
        }
    }

    private static function inputObject(array $props, array $required, McpSchemaDeriver $deriver): array {
        $schema = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
        if ($required) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;
        if ($deriver->getDefs()) {
            $schema['$defs'] = $deriver->getDefs();
        }
        return $schema;
    }

    private static function withDescription(array $schema, string $description): array {
        return $description === '' ? $schema : McpSchemaDeriver::describe($schema, $description);
    }

    /**
     * An explicit inputSchema must name exactly the bindable arguments and
     * require every argument the derived schema requires.
     */
    private static function checkInputOverride(array $override, array $derived, string $where): void {
        $want = array_keys((array)($derived['properties'] ?? []));
        $have = array_keys((array)($override['properties'] ?? []));
        sort($want);
        sort($have);
        if ($want !== $have) {
            throw new McpDefinitionException($where . 'inputSchema properties [' . implode(', ', $have)
                . '] must match the tool arguments [' . implode(', ', $want) . ']');
        }
        $missing = array_diff((array)($derived['required'] ?? []), (array)($override['required'] ?? []));
        if ($missing) {
            throw new McpDefinitionException($where . 'inputSchema must list [' . implode(', ', $missing)
                . '] in "required" (they have no default)');
        }
    }

    // ----------------------------------------------------------------- output

    /** @return array{0: ?array, 1: string} output schema, wrap mode */
    private static function output(ReflectionMethod $method, McpTool $tool, bool $isRest, string $where): array {
        if ($tool->outputSchema !== null) {
            return [$tool->outputSchema, McpToolDefinition::WRAP_OBJECT];
        }

        $type = $method->getReturnType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $class = $method->getDeclaringClass();

        if ($tool->outputType !== null) {
            $vague = $type === null
                || in_array($typeName, [ResponseEntity::class, 'array', 'mixed', 'object', 'iterable'], true);
            if (!$vague) {
                throw new McpDefinitionException($where . 'outputType is redundant: the return type already '
                    . 'describes the result');
            }
            try {
                $deriver = McpSchemaDeriver::forOutput();
                $schema = $deriver->rootObjectSchema(ltrim($tool->outputType, '\\'), 'outputType');
            } catch (McpSchemaException $e) {
                throw new McpDefinitionException($where . $e->getMessage(), 0, $e);
            }
            return [self::withDefs($schema, $deriver), McpToolDefinition::WRAP_OBJECT];
        }

        if ($type === null || in_array($typeName, ['void', 'never', 'null', ResponseEntity::class], true)) {
            return [null, McpToolDefinition::WRAP_NONE];
        }

        $deriver = $isRest ? McpSchemaDeriver::forRestOutput() : McpSchemaDeriver::forOutput();
        $docReturn = PhpDocTypeParser::returnTag($method->getDocComment());
        try {
            $isDto = $type instanceof ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull()
                && class_exists($typeName) && !enum_exists($typeName)
                && !is_a($typeName, \DateTimeInterface::class, true);
            if ($isDto && !$isRest) {
                $schema = $deriver->rootObjectSchema($typeName, 'return value');
                return [self::withDefs($schema, $deriver), McpToolDefinition::WRAP_OBJECT];
            }
            $schema = $deriver->valueSchema($type, $docReturn['type'] ?? null, $class, 'return value');
        } catch (McpSchemaException) {
            // Output schemas are optional: the result is sent as text only.
            return [null, McpToolDefinition::WRAP_NONE];
        }

        $description = $docReturn['description'] ?? '';
        $t = $schema['type'] ?? null;
        if ($t === 'object') {
            $out = $description === '' ? $schema : McpSchemaDeriver::describe($schema, $description);
            return [self::withDefs($out, $deriver), McpToolDefinition::WRAP_OBJECT];
        }
        $key = $t === 'array' ? 'items' : 'result';
        $inner = $description === '' ? $schema : McpSchemaDeriver::describe($schema, $description);
        $wrapped = ['type' => 'object', 'properties' => [$key => $inner], 'required' => [$key]];
        return [
            self::withDefs($wrapped, $deriver),
            $key === 'items' ? McpToolDefinition::WRAP_ITEMS : McpToolDefinition::WRAP_RESULT,
        ];
    }

    private static function withDefs(array $schema, McpSchemaDeriver $deriver): array {
        if ($deriver->getDefs()) {
            $schema['$defs'] = $deriver->getDefs();
        }
        return $schema;
    }
}
