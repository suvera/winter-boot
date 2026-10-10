<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\exception;

use dev\winterframework\exception\WinterException;

/**
 * A PHP type that can't be described as JSON Schema. Input derivation
 * turns it into a McpDefinitionException; output derivation omits the
 * output schema instead.
 */
class McpSchemaException extends WinterException {
}
