<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\exception\FileNotFoundException;
use dev\winterframework\exception\PropertyException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\io\PropertySource;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\PropertyLoader;

final class WinterPropertyContext implements PropertyContext {
    const CONFIG_FILE_NAME = 'application';
    private array $data = [];

    /** @var PropertySource[] */
    protected array $sources = [];

    public function __construct(
        private array $configDirs,
        private ?string $profile = null,
        private string $configFileName = self::CONFIG_FILE_NAME
    ) {
        $this->loadProperties();
    }

    public function set(string $name, mixed $value): mixed {
        $val = null;
        if (array_key_exists($name, $this->data)) {
            $val = $this->data[$name];
        }
        $this->data[$name] = $value;

        return $val;
    }

    protected function addSource(string $name, PropertySource $source): void {
        if (strlen($name) > 0 && $name[0] != '$') {
            $name = '$' . $name;
        }
        $this->sources[$name] = $source;
    }

    protected function parseValue(mixed &$val, mixed $key = null): void {

        if (is_string($val) && strlen($val) > 0 && $val[0] == '$') {
            $parts = explode('.', $val, 2);
            if (isset($parts[1])
                && isset($this->sources[$parts[0]])
            ) {
                if (!$this->sources[$parts[0]]->has($parts[1])) {
                    throw new PropertyException("No such property exists with name " . json_encode($key)
                        . ' in the Property source ' . $parts[0]);
                }
                $val = $this->sources[$parts[0]]->get($parts[1]);
            }
        } else if (is_array($val)) {
            array_walk_recursive($val, [$this, 'parseValue']);
        }
    }

    public function get(string $name, mixed $default = null): mixed {
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
        $value = self::get($name, $default);
        if (is_bool($value)) {
            return $value;
        }
        $val = strtolower($value);
        switch ($val) {
            case 'true':
            case '1':
                return true;

            case 'false':
            case '0':
            case null:
            case '':
                return false;
        }
        throw new PropertyException("property " . json_encode($name) . ' is not of type "boolean" but got "'
            . var_export($value, true) . '"');
    }

    public function getInt(string $name, int $default = null): int {
        $val = self::get($name, $default);
        if (!is_numeric($val)) {
            throw new PropertyException("property " . json_encode($name) . ' is not of type "integer"');
        }
        return intval($val);
    }

    public function getFloat(string $name, float $default = null): float {
        $val = self::get($name, $default);
        if (!is_numeric($val)) {
            throw new PropertyException("property " . json_encode($name) . ' is not of type "float"');
        }
        return floatval($val);
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
            if (isset($data['banner.location'])) {
                if (is_string($data['banner.location'])) {
                    if ($data['banner.location'][0] != '/') {
                        $data['banner.location'] = $configFile . DIRECTORY_SEPARATOR . $data['banner.location'];
                    }
                } else {
                    unset($data['banner.location']);
                }
            }
            $this->data = array_merge($this->data, $data);
        }

        if (isset($this->data['propertySources']) && is_array($this->data['propertySources'])) {
            foreach ($this->data['propertySources'] as $srcConfig) {
                Arrays::assertKey($srcConfig, 'name', 'Could not find "name" property on "propertySources"');
                Arrays::assertKey($srcConfig, 'provider', 'Could not find "provider" property on "propertySources"');
                $cls = $srcConfig['provider'];
                TypeAssert::objectOfIsA(
                    $cls,
                    PropertySource::class,
                    'Invalid "provider" property on "propertySources", must implement PropertySource interface',
                );

                $source = new $cls($srcConfig, $this);
                $this->addSource($srcConfig['name'], $source);
            }
        }

        $this->parseValue($this->data);
    }
}