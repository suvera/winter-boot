<?php
/** @noinspection PhpUnused */
/** @noinspection PhpNoReturnAttributeCanBeAddedInspection */

declare(strict_types=1);

namespace dev\winterframework\core;

use dev\winterframework\type\ImmutableMap;

final class System {
    private static ImmutableMap $theUnmodifiableEnvironment;
    private static array $props = [];
    private static array $phpBinaries = [
        '/usr/bin/php',
        '/usr/local/bin/php'
    ];

    public static function exit(int $status = 0): void {
        //echo "<pre>\n" . (microtime(true) - $GLOBALS['time']) . " seconds.\n";
        exit($status);
    }

    public static function currentTimeMillis(): int {
        return intval(microtime(true) * 1000);
    }

    public static function getEnv(): ImmutableMap {
        if (!isset(self::$theUnmodifiableEnvironment)) {
            self::$theUnmodifiableEnvironment = ImmutableMap::of($_ENV);
        }
        return self::$theUnmodifiableEnvironment;
    }

    public static function getEnvValue(string $name, ?string $defaultValue = null): ?string {
        return self::getEnv()->getOrDefault($name, $defaultValue);
    }

    public static function getProperties(): array {
        return self::$props;
    }

    public static function getProperty(string $key, mixed $defaultValue = null): mixed {
        return self::$props[$key] ?? $defaultValue;
    }

    public static function setProperty(string $key, mixed $value): void {
        self::$props[$key] = $value;
    }

    public static function setProperties(array $props): void {
        self::$props = $props;
    }

    public static function getPhpBinary(): string {
        if (isset($_ENV['PHP_BINARY'])) {
            return $_ENV['PHP_BINARY'];
        } else {
            foreach (self::$phpBinaries as $php) {
                if (is_file($php)) {
                    return $php;
                }
            }
        }
        return 'php';
    }

}