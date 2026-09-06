<?php

declare(strict_types=1);

namespace winterBootTests\Support;

use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\exception\PropertyException;
use dev\winterframework\io\PropertySource;

/**
 * In-memory PropertySource for tests. The dataset is chosen via the
 * "dataset" key of the propertySources entry in application.yml and
 * must be registered in the static $datasets map before the
 * WinterPropertyContext is built.
 */
class StubPropertySource implements PropertySource {

    /** @var array<string, array<string, mixed>> */
    public static array $datasets = [];

    private array $data = [];

    public function __construct(
        protected array $source,
        protected PropertyContext $defaultProps
    ) {
        $name = $source['dataset'] ?? '';
        $this->data = self::$datasets[$name] ?? [];
    }

    public function getAll(): array {
        return $this->data;
    }

    public function has(string $name): bool {
        return array_key_exists($name, $this->data);
    }

    public function get(string $name): mixed {
        if (!array_key_exists($name, $this->data)) {
            throw new PropertyException('could not found property ' . $name . '');
        }

        return $this->data[$name];
    }
}
