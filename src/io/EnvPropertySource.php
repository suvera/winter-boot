<?php
declare(strict_types=1);

namespace dev\winterframework\io;

use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\exception\PropertyException;

class EnvPropertySource implements PropertySource {
    public function __construct(
        protected array $source,
        protected PropertyContext $defaultProps
    ) {
    }

    public function getAll(): array {
        return $_ENV;
    }

    public function has(string $name): bool {
        return isset($_ENV[$name]);
    }

    public function get(string $name): mixed {
        if (!isset($_ENV[$name])) {
            throw new PropertyException('could not found property ' . $name . '');
        }

        return $_ENV[$name];
    }

}