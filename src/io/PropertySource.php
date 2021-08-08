<?php
declare(strict_types=1);

namespace dev\winterframework\io;

use dev\winterframework\core\context\PropertyContext;

interface PropertySource {

    public function __construct(array $source, PropertyContext $defaultProps);

    public function getAll(): array;

    public function has(string $name): bool;

    public function get(string $name): mixed;
}
