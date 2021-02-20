<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\util;

use dev\winterframework\exception\InvalidSyntaxException;
use Stringable;

class ComponentName implements Stringable {
    const ARG_REGEX = '/\#\{([a-zA-Z_\-0-9\.\(\)\s]+)\}/';
    const PROP_REGEX = '/\$\{([a-zA-Z_\-0-9\.\s]+)\}/';
    const FUNC_REGEX = '/^[a-zA-Z_][\w]*\s*\(\s*\)$/';
    const VAR_REGEX = '/^[a-zA-Z_][\w]*$/';

    private array $arguments = [];
    private array $properties = [];

    public function __construct(
        private string $name
    ) {
        $this->parse();
    }

    public function getArguments(): array {
        return $this->arguments;
    }

    public function hasArguments(): bool {
        return count($this->arguments) > 0;
    }

    public function getProperties(): array {
        return $this->properties;
    }

    public function hasProperties(): bool {
        return count($this->properties) > 0;
    }

    public function getName(): string {
        return $this->name;
    }

    private function parse(): void {
        $this->name = trim($this->name);
        if (strlen($this->name) == 0) {
            return;
        }

        $argMatches = [];
        preg_match_all(self::ARG_REGEX, $this->name, $argMatches);
        //print_r($argMatches);
        if (!empty($argMatches[1])) {
            $this->parseArgs($argMatches[1]);
        }

        $propMatches = [];
        preg_match_all(self::PROP_REGEX, $this->name, $propMatches);
        //print_r($propMatches);
        if (!empty($propMatches[1])) {
            $this->parseProps($propMatches[1]);
        }
    }

    private function parseArgs(array $values): void {
        foreach ($values as $value) {
            $parts = explode('.', $value);

            $cleaned = [];
            foreach ($parts as $idx => $part) {
                $part = preg_replace('/\s+/', '', $part);

                if (empty($part)) {
                    throw new InvalidSyntaxException('Empty/Invalid argument name "'
                        . $part
                        . '" supplied in the stereo component "'
                        . $this->name . '"');
                }

                /** @noinspection PhpStatementHasEmptyBodyInspection */
                if (preg_match(self::FUNC_REGEX, $part)) {
                    // function name received
                } else if (preg_match(self::VAR_REGEX, $part)) {
                    if ($part == 'this') {
                        throw new InvalidSyntaxException('Invalid argument name "'
                            . $part
                            . '" (use "target" instead of "this" ) in the stereo component "'
                            . $this->name . '"');
                    }
                    $part = '$' . $part;
                } else {
                    throw new InvalidSyntaxException('Invalid argument name "'
                        . $part
                        . '" supplied in the stereo component "'
                        . $this->name . '"');
                }
                $cleaned[] = $part;
            }

            $code = 'return ' . implode('->', $cleaned) . ';';
            $this->arguments['#{' . $value . '}'] = $code;
        }
    }

    private function parseProps(array $propValues): void {
        foreach ($propValues as $prop) {
            $parts = explode('.', $prop);

            $cleaned = [];
            foreach ($parts as $part) {
                $part = preg_replace('/\s+/', '', $part);

                if (empty($part)) {
                    throw new InvalidSyntaxException('Empty/Invalid property name "'
                        . $part
                        . '" supplied in the stereo component "'
                        . $this->name . '"');
                }
                $cleaned[] = $part;
            }

            $this->properties['${' . $prop . '}'] = implode('.', $cleaned);
        }
    }

    public function __toString(): string {
        return $this->name;
    }
}