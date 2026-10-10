<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\schema;

/**
 * Strict checker for the JSON Schema keywords the deriver emits: type,
 * enum, const, required, properties, additionalProperties, items, anyOf,
 * minimum, maximum and $ref ("#" and "#/$defs/Name"). Other keywords
 * (format, pattern, oneOf, ...) are ignored: they are sent to the client
 * but not enforced, as documented.
 *
 * Values are JSON decoded with assoc arrays, so an empty array is
 * accepted both as an empty object and as an empty list.
 *
 * Error messages name the path and the expected type only; they never
 * echo the offending value.
 */
final class McpSchemaValidator {

    private const MAX_DEPTH = 64;

    /**
     * @return string|null first error ("order.lines[0].quantity: expected integer"), or null when valid
     */
    public static function validate(mixed $value, array|\stdClass $schema, string $path = ''): ?string {
        $root = self::toArray($schema);
        return self::check($value, $root, $root, $path, 0);
    }

    private static function check(mixed $value, array $schema, array $root, string $path, int $depth): ?string {
        if ($depth > self::MAX_DEPTH) {
            return self::label($path) . 'value is nested too deeply';
        }

        if (isset($schema['$ref'])) {
            $target = self::resolveRef((string)$schema['$ref'], $root);
            if ($target === null) {
                return null; // unknown reference: not enforced
            }
            $err = self::check($value, $target, $root, $path, $depth + 1);
            if ($err !== null) {
                return $err;
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $first = null;
            foreach ($schema['anyOf'] as $alt) {
                $err = self::check($value, self::toArray($alt), $root, $path, $depth + 1);
                if ($err === null) {
                    $first = null;
                    break;
                }
                $first ??= $err;
            }
            if ($first !== null) {
                return self::label($path) . 'does not match any allowed form';
            }
        }

        if (isset($schema['type'])) {
            $types = (array)$schema['type'];
            $ok = false;
            foreach ($types as $t) {
                if (self::isType($value, (string)$t)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return self::label($path) . 'expected ' . implode(' or ', $types);
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return self::label($path) . 'unexpected value';
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $allowed = array_map(
                    fn($v) => $v === null ? 'null' : (is_string($v) ? '"' . $v . '"' : (string)json_encode($v)),
                    $schema['enum']
                );
                return self::label($path) . 'must be one of ' . implode(', ', $allowed);
            }
        }

        if ((is_int($value) || is_float($value))) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return self::label($path) . 'must be >= ' . $schema['minimum'];
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return self::label($path) . 'must be <= ' . $schema['maximum'];
            }
        }

        if (is_array($value) && self::isObject($value)) {
            $err = self::checkObject($value, $schema, $root, $path, $depth);
            if ($err !== null) {
                return $err;
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            $items = self::toArray($schema['items']);
            foreach ($value as $i => $item) {
                $err = self::check($item, $items, $root, $path . '[' . $i . ']', $depth + 1);
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return null;
    }

    private static function checkObject(array $value, array $schema, array $root, string $path, int $depth): ?string {
        $props = isset($schema['properties']) ? self::toArray($schema['properties']) : [];

        foreach ((array)($schema['required'] ?? []) as $name) {
            if (!array_key_exists($name, $value)) {
                return self::label(self::join($path, (string)$name)) . 'is required';
            }
        }

        $additional = $schema['additionalProperties'] ?? true;
        foreach ($value as $key => $item) {
            $key = (string)$key;
            if (isset($props[$key])) {
                $err = self::check($item, self::toArray($props[$key]), $root, self::join($path, $key), $depth + 1);
                if ($err !== null) {
                    return $err;
                }
            } elseif ($additional === false) {
                return self::label($path) . 'unknown property "' . self::safeKey($key) . '"';
            } elseif (is_array($additional) || $additional instanceof \stdClass) {
                $err = self::check($item, self::toArray($additional), $root, self::join($path, $key), $depth + 1);
                if ($err !== null) {
                    return $err;
                }
            }
        }
        return null;
    }

    private static function isType(mixed $v, string $type): bool {
        return match ($type) {
            'null' => $v === null,
            'boolean' => is_bool($v),
            'integer' => is_int($v) || (is_float($v) && floor($v) === $v && abs($v) < 2 ** 53),
            'number' => is_int($v) || is_float($v),
            'string' => is_string($v),
            'array' => is_array($v) && array_is_list($v),
            'object' => is_array($v) && self::isObject($v),
            default => true,
        };
    }

    /** JSON objects decode to string-keyed arrays; [] counts as both. */
    private static function isObject(array $v): bool {
        return $v === [] || !array_is_list($v);
    }

    private static function resolveRef(string $ref, array $root): ?array {
        if ($ref === '#') {
            return $root;
        }
        if (str_starts_with($ref, '#/$defs/')) {
            $name = substr($ref, 8);
            $defs = isset($root['$defs']) ? self::toArray($root['$defs']) : [];
            return isset($defs[$name]) ? self::toArray($defs[$name]) : null;
        }
        return null;
    }

    private static function toArray(mixed $schema): array {
        if ($schema instanceof \stdClass) {
            return (array)$schema;
        }
        return is_array($schema) ? $schema : [];
    }

    private static function join(string $path, string $key): string {
        return $path === '' ? $key : $path . '.' . $key;
    }

    private static function label(string $path): string {
        return $path === '' ? 'arguments: ' : $path . ': ';
    }

    /** Unknown keys are attacker-controlled: shorten and strip control characters. */
    private static function safeKey(string $key): string {
        $key = (string)preg_replace('/[^\x20-\x7E]/', '?', $key);
        return strlen($key) > 64 ? substr($key, 0, 64) . '...' : $key;
    }
}
