<?php

declare(strict_types=1);

namespace dev\winterframework\io;

use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\exception\PropertyException;

class IniPropertySource implements PropertySource {
    protected array $data = [];

    public function __construct(
        protected array $source,
        protected PropertyContext $defaultProps
    ) {
        if (!isset($this->source['filePath'])) {
            throw new PropertyException('IniPropertySource in your application.yml requires "filePath" property');
        }
        if (!file_exists($this->source['filePath'])) {
            throw new PropertyException('IniPropertySource in your application.yml requires "filePath" property to be a valid file path, but '
                . $this->source['filePath'] . ' does not exist');
        }
        $data = parse_ini_file($this->source['filePath'], false, INI_SCANNER_RAW);
        if ($data === false) {
            throw new PropertyException('IniPropertySource in your application.yml requires "filePath" property to be a valid ini file, but '
                . $this->source['filePath'] . ' is not a valid ini file');
        }
        $this->data = $data;
    }

    public function getAll(): array {
        return $this->data;
    }

    public function has(string $name): bool {
        return isset($this->data[$name]);
    }

    public function get(string $name): mixed {
        if (!isset($this->data[$name])) {
            throw new PropertyException('could not found property ' . $name . '');
        }

        return $this->data[$name];
    }
}
