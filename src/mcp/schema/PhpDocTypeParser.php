<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\schema;

use ReflectionClass;

/**
 * Reads the small PHPDoc subset MCP schema derivation understands:
 * summary text, @param / @return / @var types and their descriptions.
 *
 * Supported type forms: scalars, class names (resolved through the
 * declaring file's `use` imports), T|null, ?T, T[], list<T>,
 * array<K, T>, array{a: T, b?: T} and any nesting of these. Anything
 * else parses as "untyped", so callers fail closed on input.
 *
 * Type nodes are plain arrays:
 *   ['kind' => 'scalar', 'name' => 'int'|'float'|'string'|'bool']
 *   ['kind' => 'null'] | ['kind' => 'untyped']
 *   ['kind' => 'class', 'name' => FQCN]
 *   ['kind' => 'list', 'of' => node] | ['kind' => 'map', 'of' => node]
 *   ['kind' => 'shape', 'fields' => [name => ['type' => node, 'optional' => bool]]]
 *   ['kind' => 'union', 'of' => node[]]
 */
final class PhpDocTypeParser {

    private const SCALARS = [
        'int' => 'int', 'integer' => 'int', 'float' => 'float', 'double' => 'float',
        'string' => 'string', 'bool' => 'bool', 'boolean' => 'bool',
        'true' => 'bool', 'false' => 'bool',
    ];

    /** Cap on cached import tables (one per source file, bounded by code size). */
    private const MAX_CACHED_FILES = 2048;

    /** @var array<string, array<string, string>> file => alias => FQCN */
    private static array $imports = [];

    /**
     * Summary: the doc comment text before the first tag, whitespace-collapsed.
     */
    public static function summary(string|false $doc): string {
        if ($doc === false || $doc === '') {
            return '';
        }
        $lines = [];
        foreach (self::lines($doc) as $line) {
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }
        // First paragraph only.
        $text = trim(implode("\n", $lines));
        $para = preg_split('/\n\s*\n/', $text)[0] ?? '';
        return trim((string)preg_replace('/\s+/', ' ', $para));
    }

    /**
     * @return array<string, array{type: string, description: string}> by parameter name (no '$')
     */
    public static function params(string|false $doc): array {
        $out = [];
        foreach (self::tags($doc, 'param') as $body) {
            [$type, $rest] = self::splitType($body);
            if (!preg_match('/^&?(?:\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)\s*(.*)$/s', $rest, $m)) {
                continue;
            }
            $out[$m[1]] = ['type' => $type, 'description' => self::oneLine($m[2])];
        }
        return $out;
    }

    /** @return array{type: string, description: string}|null */
    public static function returnTag(string|false $doc): ?array {
        foreach (self::tags($doc, 'return') as $body) {
            [$type, $rest] = self::splitType($body);
            return ['type' => $type, 'description' => self::oneLine($rest)];
        }
        return null;
    }

    /** @return array{type: string, description: string}|null */
    public static function varTag(string|false $doc): ?array {
        foreach (self::tags($doc, 'var') as $body) {
            [$type, $rest] = self::splitType($body);
            // "@var Type $name desc" is allowed; drop the variable name.
            $rest = (string)preg_replace('/^\$[A-Za-z_][A-Za-z0-9_]*\s*/', '', $rest);
            return ['type' => $type, 'description' => self::oneLine($rest)];
        }
        return null;
    }

    /**
     * Parses a type expression. $context resolves short class names
     * (its namespace and its file's `use` imports); null means only fully
     * qualified names resolve.
     */
    public static function parse(string $type, ?ReflectionClass $context = null): array {
        $type = trim($type);
        if ($type === '') {
            return ['kind' => 'untyped'];
        }
        $pos = 0;
        try {
            $node = self::parseUnion($type, $pos, $context);
            self::skipWs($type, $pos);
            if ($pos !== strlen($type)) {
                return ['kind' => 'untyped'];
            }
            return $node;
        } catch (\UnexpectedValueException) {
            return ['kind' => 'untyped'];
        }
    }

    /** True when the node (or any part of it) could not be understood. */
    public static function hasUntyped(array $node): bool {
        return match ($node['kind']) {
            'untyped' => true,
            'list', 'map' => self::hasUntyped($node['of']),
            'union' => array_reduce($node['of'], fn(bool $c, array $n) => $c || self::hasUntyped($n), false),
            'shape' => array_reduce(
                $node['fields'],
                fn(bool $c, array $f) => $c || self::hasUntyped($f['type']),
                false
            ),
            default => false,
        };
    }

    // ---------------------------------------------------------------- lexing

    /** @return string[] doc lines without the comment markers */
    private static function lines(string $doc): array {
        $doc = (string)preg_replace('#^\s*/\*\*|\*/\s*$#', '', $doc);
        $out = [];
        foreach (preg_split('/\R/', $doc) as $line) {
            $out[] = trim((string)preg_replace('/^\s*\*\s?/', '', $line));
        }
        return $out;
    }

    /** @return string[] tag bodies (continuation lines joined) */
    private static function tags(string|false $doc, string $tag): array {
        if ($doc === false || $doc === '') {
            return [];
        }
        $out = [];
        $current = null;
        foreach (self::lines($doc) as $line) {
            if (str_starts_with($line, '@')) {
                if ($current !== null) {
                    $out[] = $current;
                    $current = null;
                }
                if (preg_match('/^@' . $tag . '\s+(.*)$/s', $line, $m)) {
                    $current = $m[1];
                }
            } elseif ($current !== null && $line !== '') {
                $current .= ' ' . $line;
            } elseif ($current !== null) {
                $out[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }
        return $out;
    }

    /**
     * Splits "array<string, int> rest" into the type (balanced brackets,
     * spaces allowed inside them) and the remainder.
     * @return array{0: string, 1: string}
     */
    private static function splitType(string $body): array {
        $body = ltrim($body);
        $depth = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '<' || $c === '{' || $c === '(') {
                $depth++;
            } elseif ($c === '>' || $c === '}' || $c === ')') {
                $depth--;
            } elseif ($depth <= 0 && ctype_space($c)) {
                // "Foo | null" style: keep going over a union bar.
                $j = $i;
                while ($j < $len && ctype_space($body[$j])) {
                    $j++;
                }
                if ($j < $len && $body[$j] === '|') {
                    $i = $j;
                    continue;
                }
                if ($i > 0 && $body[$i - 1] === '|') {
                    continue;
                }
                return [substr($body, 0, $i), ltrim(substr($body, $i))];
            }
        }
        return [$body, ''];
    }

    private static function oneLine(string $text): string {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    // --------------------------------------------------------------- parsing

    private static function parseUnion(string $s, int &$pos, ?ReflectionClass $ctx): array {
        $parts = [self::parseAtom($s, $pos, $ctx)];
        self::skipWs($s, $pos);
        while ($pos < strlen($s) && $s[$pos] === '|') {
            $pos++;
            $parts[] = self::parseAtom($s, $pos, $ctx);
            self::skipWs($s, $pos);
        }
        return count($parts) === 1 ? $parts[0] : ['kind' => 'union', 'of' => $parts];
    }

    private static function parseAtom(string $s, int &$pos, ?ReflectionClass $ctx): array {
        self::skipWs($s, $pos);
        if ($pos < strlen($s) && $s[$pos] === '?') {
            $pos++;
            return ['kind' => 'union', 'of' => [self::parseAtom($s, $pos, $ctx), ['kind' => 'null']]];
        }
        if (!preg_match('/\G\\\\?[A-Za-z_][A-Za-z0-9_\\\\-]*/', $s, $m, 0, $pos)) {
            throw new \UnexpectedValueException('type name expected');
        }
        $name = $m[0];
        $pos += strlen($name);
        $lower = strtolower($name);

        if (($lower === 'list' || $lower === 'non-empty-list') && self::peek($s, $pos) === '<') {
            $pos++;
            $of = self::parseUnion($s, $pos, $ctx);
            self::expect($s, $pos, '>');
            $node = ['kind' => 'list', 'of' => $of];
        } elseif (($lower === 'array' || $lower === 'non-empty-array') && self::peek($s, $pos) === '<') {
            $pos++;
            $first = self::parseUnion($s, $pos, $ctx);
            self::skipWs($s, $pos);
            if (self::peek($s, $pos) === ',') {
                $pos++;
                $value = self::parseUnion($s, $pos, $ctx);
                self::expect($s, $pos, '>');
                $isStringKey = $first['kind'] === 'scalar' && $first['name'] === 'string';
                $isIntKey = $first['kind'] === 'scalar' && $first['name'] === 'int';
                if (!$isStringKey && !$isIntKey) {
                    throw new \UnexpectedValueException('unsupported array key');
                }
                $node = $isStringKey ? ['kind' => 'map', 'of' => $value] : ['kind' => 'list', 'of' => $value];
            } else {
                self::expect($s, $pos, '>');
                $node = ['kind' => 'list', 'of' => $first];
            }
        } elseif ($lower === 'array' && self::peek($s, $pos) === '{') {
            $pos++;
            $node = ['kind' => 'shape', 'fields' => self::parseShapeFields($s, $pos, $ctx)];
        } elseif (isset(self::SCALARS[$lower])) {
            $node = ['kind' => 'scalar', 'name' => self::SCALARS[$lower]];
        } elseif ($lower === 'null') {
            $node = ['kind' => 'null'];
        } elseif (in_array($lower, ['mixed', 'array', 'object', 'iterable', 'callable', 'resource', 'void',
            'static', 'self', '$this', 'never', 'scalar', 'numeric'], true)) {
            $node = ['kind' => 'untyped'];
        } else {
            $class = self::resolveClass($name, $ctx);
            $node = $class === null ? ['kind' => 'untyped'] : ['kind' => 'class', 'name' => $class];
        }

        while (substr($s, $pos, 2) === '[]') {
            $pos += 2;
            $node = ['kind' => 'list', 'of' => $node];
        }
        return $node;
    }

    /** @return array<string, array{type: array, optional: bool}> */
    private static function parseShapeFields(string $s, int &$pos, ?ReflectionClass $ctx): array {
        $fields = [];
        self::skipWs($s, $pos);
        if (self::peek($s, $pos) === '}') {
            $pos++;
            return $fields;
        }
        while (true) {
            self::skipWs($s, $pos);
            if (!preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)(\?)?\s*:/', $s, $m, 0, $pos)) {
                throw new \UnexpectedValueException('shape key expected');
            }
            $pos += strlen($m[0]);
            $fields[$m[1]] = ['type' => self::parseUnion($s, $pos, $ctx), 'optional' => ($m[2] ?? '') === '?'];
            self::skipWs($s, $pos);
            $c = self::peek($s, $pos);
            $pos++;
            if ($c === '}') {
                return $fields;
            }
            if ($c !== ',') {
                throw new \UnexpectedValueException('"," or "}" expected');
            }
            self::skipWs($s, $pos);
            if (self::peek($s, $pos) === '}') {
                $pos++;
                return $fields;
            }
        }
    }

    private static function peek(string $s, int $pos): string {
        return $pos < strlen($s) ? $s[$pos] : '';
    }

    private static function expect(string $s, int &$pos, string $char): void {
        self::skipWs($s, $pos);
        if (self::peek($s, $pos) !== $char) {
            throw new \UnexpectedValueException('"' . $char . '" expected');
        }
        $pos++;
    }

    private static function skipWs(string $s, int &$pos): void {
        $len = strlen($s);
        while ($pos < $len && ctype_space($s[$pos])) {
            $pos++;
        }
    }

    // ------------------------------------------------------- name resolution

    private static function resolveClass(string $name, ?ReflectionClass $ctx): ?string {
        if (str_starts_with($name, '\\')) {
            $fq = ltrim($name, '\\');
            return self::exists($fq) ? $fq : null;
        }
        if ($ctx !== null) {
            $first = explode('\\', $name, 2);
            $imports = self::importsOf($ctx);
            $alias = strtolower($first[0]);
            if (isset($imports[$alias])) {
                $fq = $imports[$alias] . (isset($first[1]) ? '\\' . $first[1] : '');
                return self::exists($fq) ? $fq : null;
            }
            $ns = $ctx->getNamespaceName();
            $fq = $ns === '' ? $name : $ns . '\\' . $name;
            if (self::exists($fq)) {
                return $fq;
            }
        }
        return self::exists($name) ? $name : null;
    }

    private static function exists(string $class): bool {
        return class_exists($class) || interface_exists($class) || enum_exists($class);
    }

    /** @return array<string, string> lower-case alias => FQCN */
    private static function importsOf(ReflectionClass $ctx): array {
        $file = $ctx->getFileName();
        if ($file === false) {
            return [];
        }
        if (isset(self::$imports[$file])) {
            return self::$imports[$file];
        }
        if (count(self::$imports) >= self::MAX_CACHED_FILES) {
            unset(self::$imports[array_key_first(self::$imports)]);
        }
        $code = @file_get_contents($file);
        return self::$imports[$file] = is_string($code) ? self::parseImports($code) : [];
    }

    /**
     * Class imports (`use A\B;`, `use A\B as C;`, `use A\{B, C as D};`) at
     * namespace level. Function/const imports and trait uses are skipped.
     * @return array<string, string>
     */
    public static function parseImports(string $code): array {
        $tokens = token_get_all($code);
        $imports = [];
        $depth = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === '{') {
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                continue;
            }
            if (!is_array($t) || $t[0] !== T_USE || $depth > 0) {
                continue;
            }
            $stmt = '';
            for ($i++; $i < $n && $tokens[$i] !== ';'; $i++) {
                $stmt .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }
            $stmt = trim($stmt);
            if (preg_match('/^(function|const)\s/i', $stmt)) {
                continue;
            }
            foreach (self::expandUse($stmt) as $alias => $fq) {
                $imports[strtolower($alias)] = $fq;
            }
        }
        return $imports;
    }

    /** @return array<string, string> */
    private static function expandUse(string $stmt): array {
        $out = [];
        if (preg_match('/^(.*?)\\\\?\{(.*)\}$/s', $stmt, $m)) {
            $prefix = trim(trim($m[1]), '\\');
            foreach (explode(',', $m[2]) as $part) {
                $out += self::expandUse($prefix . '\\' . trim($part));
            }
            return $out;
        }
        if (preg_match('/^\\\\?([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?$/i', trim($stmt), $m)) {
            $fq = trim($m[1], '\\');
            $alias = $m[2] ?? substr($fq, (int)strrpos('\\' . $fq, '\\'));
            $out[$alias] = $fq;
        }
        return $out;
    }
}
