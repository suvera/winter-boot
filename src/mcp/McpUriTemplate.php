<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

/**
 * The simple RFC 6570 form MCP resource templates use: literal text and
 * {name} placeholders. A placeholder matches one path segment (no "/").
 */
final class McpUriTemplate {

    /** @var string[] */
    private array $variables;
    private string $regex;

    public function __construct(
        public readonly string $template
    ) {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $m);
        $this->variables = $m[1];
        $parts = preg_split('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $template);
        $regex = '';
        foreach ($parts as $i => $literal) {
            $regex .= preg_quote($literal, '#');
            if (isset($this->variables[$i])) {
                $regex .= '(?P<' . $this->variables[$i] . '>[^/]+)';
            }
        }
        $this->regex = '#^' . $regex . '$#';
    }

    /** @return string[] placeholder names, in order */
    public function getVariables(): array {
        return $this->variables;
    }

    public function isTemplate(): bool {
        return $this->variables !== [];
    }

    /**
     * @return array<string, string>|null placeholder values (percent-decoded), or null when $uri doesn't match
     */
    public function match(string $uri): ?array {
        if (!preg_match($this->regex, $uri, $m)) {
            return null;
        }
        $out = [];
        foreach ($this->variables as $v) {
            $out[$v] = rawurldecode($m[$v]);
        }
        return $out;
    }
}
