<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\exception\FileNotFoundException;
use dev\winterframework\exception\PropertyException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\util\PropertyLoader;

final class WinterPropertyContext implements PropertyContext {
    const CONFIG_FILE_NAME = 'application';
    private array $data = [];

    public function __construct(
        private array $configDirs,
        private ?string $profile = null,
        private string $configFileName = self::CONFIG_FILE_NAME
    ) {
        $this->loadProperties();
    }

    public function get(string $name, mixed $default = null): string|int|float|bool|null {
        if (array_key_exists($name, $this->data)) {
            $val = $this->data[$name];

            if (isset($default) && !isset($val)) {
                return $default;
            }
            return $val;
        }

        if (isset($default)) {
            return $default;
        }

        throw new PropertyException("No such property exists with name " . json_encode($name));
    }

    public function getStr(string $name, string $default = null): string {
        $val = self::get($name, $default);
        return match (gettype($val)) {
            'string' => $val,
            'boolean' => $val ? 'true' : 'false',
            default => strval($val),
        };
    }

    public function getBool(string $name, bool $default = null): bool {
        return boolval(self::get($name, $default));
    }

    public function getInt(string $name, int $default = null): int {
        return intval(self::get($name, $default));
    }

    public function getFloat(string $name, float $default = null): float {
        return floatval(self::get($name, $default));
    }

    public function getAll(): array {
        return $this->data;
    }

    public function has(string $name): bool {
        return array_key_exists($name, $this->data);
    }

    private function loadProperties() {
        $configFileName = $this->configFileName
            . (isset($this->profile) && strlen($this->profile) ? '-' . $this->profile : '') . '.yml';

        $configFiles = DirectoryScanner::scanFileInDirectories($this->configDirs, $configFileName);

        if (empty($configFiles)) {
            throw new FileNotFoundException("Could not find '$configFileName' in any config directory "
                . json_encode($this->configDirs));
        }

        foreach ($configFiles as $configFile) {
            $data = PropertyLoader::loadProperties($configFile);
            $this->data = array_merge($this->data, $data);
        }
    }
}