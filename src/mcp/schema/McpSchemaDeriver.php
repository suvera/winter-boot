<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\schema;

use BackedEnum;
use DateTimeInterface;
use dev\winterframework\mcp\exception\McpSchemaException;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\type\FloatList;
use dev\winterframework\type\IntegerList;
use dev\winterframework\type\StringList;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Derives JSON Schema from PHP types and the PHPDoc subset read by
 * PhpDocTypeParser. Pure: no container, no I/O beyond reading source
 * files for `use` imports, so it is unit-testable on its own.
 *
 * One instance collects the "$defs" of one schema. DTO classes go to
 * "$defs" and are referenced with "$ref"; an output root class is
 * inlined and a reference back to it is {"$ref": "#"}.
 *
 * A type that can't be described throws McpSchemaException naming the
 * place (parameter, property); callers decide whether that is a boot
 * error (input) or "no output schema" (output).
 */
final class McpSchemaDeriver {

    public const MAX_DEPTH = 8;

    /** @var array<string, array> def name => schema */
    private array $defs = [];

    /** @var array<string, string> FQCN => def name */
    private array $defNames = [];

    private ?string $rootClass = null;

    private int $depth = 0;

    /**
     * @param bool $input input schemas close objects (additionalProperties: false)
     * @param bool $plainJsonEncode the value is serialized by json_encode()
     *        (a REST response body), which ignores #[JsonProperty] names and
     *        can't encode DateTime or unit enums the way the schema would
     *        say; only backed enums are describable then
     */
    public function __construct(
        private readonly bool $input,
        private readonly bool $plainJsonEncode = false
    ) {
    }

    public static function forInput(): self {
        return new self(true);
    }

    /** Output serialized by McpResultSerializer (service tools). */
    public static function forOutput(): self {
        return new self(false);
    }

    /** Output serialized by the HTTP response renderer (REST tools). */
    public static function forRestOutput(): self {
        return new self(false, true);
    }

    /** "$defs" collected so far (empty array when none). */
    public function getDefs(): array {
        return $this->defs;
    }

    /**
     * Schema of one value from its PHP type plus optional PHPDoc type
     * string (the PHPDoc wins only where the PHP type is vague, e.g.
     * `array`).
     *
     * @param string $where used in error messages, e.g. "parameter $ids"
     */
    public function valueSchema(
        ?ReflectionType $type,
        ?string $docType,
        ReflectionClass $context,
        string $where
    ): array {
        $node = self::mergeNodes(
            $type === null ? ['kind' => 'untyped'] : self::nodeFromReflection($type, $context),
            $docType === null || $docType === '' ? null : PhpDocTypeParser::parse($docType, $context)
        );
        return $this->nodeSchema($node, $where);
    }

    /**
     * Inline object schema of a class used as the output root.
     * @throws McpSchemaException when the class isn't a describable DTO
     */
    public function rootObjectSchema(string $class, string $where): array {
        $this->rootClass = $class;
        $schema = $this->classSchema($class, $where, true);
        if (($schema['type'] ?? null) !== 'object') {
            throw new McpSchemaException($where . ': ' . $class . ' does not describe a JSON object');
        }
        return $schema;
    }

    /** Schema for a parsed PHPDoc / reflection type node. */
    public function nodeSchema(array $node, string $where): array {
        switch ($node['kind']) {
            case 'scalar':
                return ['type' => match ($node['name']) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    default => 'string',
                }];
            case 'null':
                return ['type' => 'null'];
            case 'class':
                return $this->classSchema($node['name'], $where, false);
            case 'list':
                return ['type' => 'array', 'items' => $this->nodeSchema($node['of'], $where . '[]')];
            case 'map':
                return [
                    'type' => 'object',
                    'additionalProperties' => $this->nodeSchema($node['of'], $where . '{}'),
                ];
            case 'shape':
                $props = [];
                $required = [];
                foreach ($node['fields'] as $name => $field) {
                    $props[$name] = $this->nodeSchema($field['type'], $where . '.' . $name);
                    if (!$field['optional']) {
                        $required[] = $name;
                    }
                }
                $schema = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
                if ($required) {
                    $schema['required'] = $required;
                }
                if ($this->input) {
                    $schema['additionalProperties'] = false;
                }
                return $schema;
            case 'union':
                $members = $node['of'];
                $hasNull = false;
                $rest = [];
                foreach ($members as $m) {
                    if ($m['kind'] === 'null') {
                        $hasNull = true;
                    } else {
                        $rest[] = $m;
                    }
                }
                if (!$rest) {
                    return ['type' => 'null'];
                }
                $schema = count($rest) === 1
                    ? $this->nodeSchema($rest[0], $where)
                    : self::anyOf(array_map(fn(array $m) => $this->nodeSchema($m, $where), $rest));
                return $hasNull ? self::nullable($schema) : $schema;
            default:
                throw new McpSchemaException($where . ' has no describable type');
        }
    }

    /** Adds null to a schema in the least surprising form. */
    public static function nullable(array $schema): array {
        if (isset($schema['type']) && !isset($schema['$ref'])) {
            $types = (array)$schema['type'];
            if (!in_array('null', $types, true)) {
                $types[] = 'null';
            }
            $schema['type'] = count($types) === 1 ? $types[0] : $types;
            if (isset($schema['enum']) && !in_array(null, $schema['enum'], true)) {
                $schema['enum'][] = null;
            }
            return $schema;
        }
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    private static function anyOf(array $schemas): array {
        // Collapse plain scalar alternatives into one "type" list.
        $types = [];
        foreach ($schemas as $s) {
            if (count($s) !== 1 || !isset($s['type']) || !is_string($s['type'])) {
                return ['anyOf' => $schemas];
            }
            $types[] = $s['type'];
        }
        return ['type' => array_values(array_unique($types))];
    }

    // ------------------------------------------------------------ class types

    private function classSchema(string $class, string $where, bool $inlineRoot): array {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            throw new McpSchemaException($where . ': unknown class ' . $class);
        }
        if ($this->plainJsonEncode && !is_a($class, BackedEnum::class, true)) {
            throw new McpSchemaException($where . ': ' . $class
                . ' in a REST response body has no reliable JSON shape; set outputType or outputSchema');
        }
        if (enum_exists($class)) {
            return self::enumSchema($class);
        }
        if (is_a($class, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (is_a($class, StringList::class, true)) {
            return ['type' => 'array', 'items' => ['type' => 'string']];
        }
        if (is_a($class, IntegerList::class, true)) {
            return ['type' => 'array', 'items' => ['type' => 'integer']];
        }
        if (is_a($class, FloatList::class, true)) {
            return ['type' => 'array', 'items' => ['type' => 'number']];
        }

        $ref = new ReflectionClass($class);
        if ($ref->isInterface() || $ref->isAbstract()) {
            throw new McpSchemaException($where . ': ' . $class . ' is abstract; use a concrete DTO class');
        }

        if (!$inlineRoot && $class === $this->rootClass) {
            return ['$ref' => '#'];
        }
        if (!$inlineRoot && isset($this->defNames[$class])) {
            return ['$ref' => '#/$defs/' . $this->defNames[$class]];
        }

        if ($this->depth >= self::MAX_DEPTH) {
            throw new McpSchemaException($where . ': DTOs are nested deeper than ' . self::MAX_DEPTH . ' levels');
        }

        $defName = null;
        if (!$inlineRoot) {
            $defName = $this->allocateDefName($ref);
            // Reserve before descending so self references terminate.
            $this->defNames[$class] = $defName;
            $this->defs[$defName] = [];
        }

        $this->depth++;
        try {
            $schema = $this->objectSchema($ref);
        } finally {
            $this->depth--;
        }

        if ($inlineRoot) {
            return $schema;
        }
        $this->defs[$defName] = $schema;
        return ['$ref' => '#/$defs/' . $defName];
    }

    private function allocateDefName(ReflectionClass $ref): string {
        $name = $ref->getShortName();
        $taken = array_flip($this->defNames);
        if (isset($taken[$name]) && $taken[$name] !== $ref->getName()) {
            $name = str_replace('\\', '.', $ref->getName());
        }
        return $name;
    }

    private static function enumSchema(string $class): array {
        $enum = new ReflectionEnum($class);
        if ($enum->isBacked()) {
            /** @var class-string<BackedEnum> $class */
            $values = array_map(fn(BackedEnum $c) => $c->value, $class::cases());
            $type = (string)$enum->getBackingType() === 'int' ? 'integer' : 'string';
            return ['type' => $type, 'enum' => $values];
        }
        /** @var class-string<UnitEnum> $class */
        return ['type' => 'string', 'enum' => array_map(fn(UnitEnum $c) => $c->name, $class::cases())];
    }

    /**
     * Object schema of a DTO: its public non-static properties plus any
     * property carrying #[JsonProperty], named as JSON sees them.
     */
    private function objectSchema(ReflectionClass $ref): array {
        $props = [];
        $required = [];
        foreach (self::dtoProperties($ref) as $jsonName => $prop) {
            $where = $ref->getShortName() . '::$' . $prop->getName();
            $doc = $prop->getDocComment();
            $var = PhpDocTypeParser::varTag($doc);

            $docType = $var['type'] ?? null;
            $listClass = self::listClassOf($prop);
            if ($listClass !== null) {
                $docType = '\\' . $listClass . '[]';
            }

            $schema = $this->valueSchema($prop->getType(), $docType, $prop->getDeclaringClass(), $where);

            $description = PhpDocTypeParser::summary($doc) ?: ($var['description'] ?? '');
            if ($description !== '') {
                $schema = self::describe($schema, $description);
            }

            [$hasDefault, $default] = self::propertyDefault($prop);
            if ($hasDefault && self::isJsonValue($default)) {
                $schema['default'] = self::jsonDefault($default, $schema);
            }

            $type = $prop->getType();
            $allowsNull = $type === null || $type->allowsNull();
            if (!$hasDefault && !$allowsNull) {
                $required[] = $jsonName;
            }
            $props[$jsonName] = $schema;
        }

        $schema = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * Properties a DTO exposes as JSON, keyed by JSON name: public
     * non-static ones and any carrying #[JsonProperty] (whose first name
     * wins, as ObjectCreator reads it).
     * @return array<string, ReflectionProperty>
     */
    public static function dtoProperties(ReflectionClass $ref): array {
        $out = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $attr = $prop->getAttributes(JsonProperty::class)[0] ?? null;
            if (!$prop->isPublic() && $attr === null) {
                continue;
            }
            $name = $prop->getName();
            if ($attr !== null) {
                $jsonName = $attr->getArguments()['name'] ?? $attr->getArguments()[0] ?? '';
                if (is_array($jsonName)) {
                    $jsonName = $jsonName[0] ?? '';
                }
                if (is_string($jsonName) && $jsonName !== '') {
                    $name = $jsonName;
                }
            }
            $out[$name] = $prop;
        }
        return $out;
    }

    private static function listClassOf(ReflectionProperty $prop): ?string {
        $attr = $prop->getAttributes(JsonProperty::class)[0] ?? null;
        if ($attr === null) {
            return null;
        }
        $cls = $attr->getArguments()['listClass'] ?? '';
        return is_string($cls) && $cls !== '' ? ltrim($cls, '\\') : null;
    }

    /** @return array{0: bool, 1: mixed} */
    private static function propertyDefault(ReflectionProperty $prop): array {
        if ($prop->isPromoted()) {
            $ctor = $prop->getDeclaringClass()->getConstructor();
            foreach ($ctor?->getParameters() ?? [] as $param) {
                if ($param->getName() === $prop->getName()) {
                    return $param->isDefaultValueAvailable()
                        ? [true, $param->getDefaultValue()]
                        : [false, null];
                }
            }
            return [false, null];
        }
        return $prop->hasDefaultValue() && ($prop->getDefaultValue() !== null || $prop->getType()?->allowsNull())
            ? [true, $prop->getDefaultValue()]
            : [false, null];
    }

    public static function isJsonValue(mixed $v): bool {
        if ($v === null || is_scalar($v) || $v instanceof BackedEnum || $v instanceof UnitEnum) {
            return true;
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                if (!self::isJsonValue($item)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** Default value as JSON: enums by value/name, [] as {} for object schemas. */
    public static function jsonDefault(mixed $v, array $schema = []): mixed {
        if ($v instanceof BackedEnum) {
            return $v->value;
        }
        if ($v instanceof UnitEnum) {
            return $v->name;
        }
        if ($v === [] && ($schema['type'] ?? null) === 'object') {
            return new \stdClass();
        }
        if (is_array($v)) {
            return array_map(fn($i) => self::jsonDefault($i), $v);
        }
        return $v;
    }

    /** Attaches a description, keeping "$ref" schemas valid. */
    public static function describe(array $schema, string $description): array {
        $schema['description'] = $description;
        return $schema;
    }

    // ------------------------------------------------------- reflection nodes

    /** Converts a reflection type to a PhpDocTypeParser node. */
    public static function nodeFromReflection(ReflectionType $type, ReflectionClass $context): array {
        if ($type instanceof ReflectionUnionType) {
            return ['kind' => 'union', 'of' => array_map(
                fn(ReflectionType $t) => self::nodeFromReflection($t, $context),
                $type->getTypes()
            )];
        }
        if ($type instanceof ReflectionIntersectionType || !$type instanceof ReflectionNamedType) {
            return ['kind' => 'untyped'];
        }
        $name = $type->getName();
        $node = match (strtolower($name)) {
            'int' => ['kind' => 'scalar', 'name' => 'int'],
            'float' => ['kind' => 'scalar', 'name' => 'float'],
            'string' => ['kind' => 'scalar', 'name' => 'string'],
            'bool', 'true', 'false' => ['kind' => 'scalar', 'name' => 'bool'],
            'null' => ['kind' => 'null'],
            'self', 'static' => ['kind' => 'class', 'name' => $context->getName()],
            'mixed', 'array', 'iterable', 'object', 'callable', 'void', 'never' => ['kind' => 'untyped'],
            default => ['kind' => 'class', 'name' => $name],
        };
        if ($type->allowsNull() && !in_array(strtolower($name), ['null', 'mixed'], true)) {
            return ['kind' => 'union', 'of' => [$node, ['kind' => 'null']]];
        }
        return $node;
    }

    /**
     * The PHP type wins unless it is vague (array, mixed, untyped) and the
     * PHPDoc type is fully understood; PHP's nullability is kept.
     */
    public static function mergeNodes(array $php, ?array $doc): array {
        if ($doc === null || PhpDocTypeParser::hasUntyped($doc)) {
            return $php;
        }
        $phpNullable = false;
        $core = $php;
        if ($php['kind'] === 'union') {
            $rest = array_values(array_filter($php['of'], fn(array $n) => $n['kind'] !== 'null'));
            $phpNullable = count($rest) !== count($php['of']);
            $core = count($rest) === 1 ? $rest[0] : ['kind' => 'union', 'of' => $rest];
        }
        if (!PhpDocTypeParser::hasUntyped($core)) {
            return $php;
        }
        if ($phpNullable && !self::nodeAllowsNull($doc)) {
            return ['kind' => 'union', 'of' => [$doc, ['kind' => 'null']]];
        }
        return $doc;
    }

    private static function nodeAllowsNull(array $node): bool {
        if ($node['kind'] === 'null') {
            return true;
        }
        if ($node['kind'] === 'union') {
            foreach ($node['of'] as $n) {
                if ($n['kind'] === 'null') {
                    return true;
                }
            }
        }
        return false;
    }
}
