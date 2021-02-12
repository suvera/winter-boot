<?php

declare(strict_types=1);

namespace dev\winterframework\core\apc;

use dev\winterframework\util\log\Wlf4p;

class ApcCache {
    use Wlf4p;

    const PREFIX = 'winter.';

    public static function isEnabled(): bool {
        return extension_loaded('apcu') && \apcu_enabled();
    }

    public static function cache(string $name, mixed $value, int $ttl): void {
        $name = self::PREFIX . $name;
        self::cacheItem($name, $value, $ttl);
    }

    private static function cacheItem(string $name, mixed $value, int $ttl): void {
        if (apcu_store($name, $value, $ttl) === false) {
            self::logError('Could not store variable to APC cache');
        }
    }

    public static function get(string $name): mixed {
        $name = self::PREFIX . $name;
        return apcu_fetch($name);
    }

    public static function exists(string $name): bool {
        $name = self::PREFIX . $name;
        return apcu_exists($name);
    }

    public static function getSafe(string $name, callable $provider, int $ttl): mixed {
        $name = self::PREFIX . $name;
        if (apcu_exists($name)) {
            return apcu_fetch($name);
        }
        $val = $provider();
        self::cacheItem($name, $val, $ttl);

        return $val;
    }

    public static function delete(string $name): void {
        $name = self::PREFIX . $name;
        apcu_delete($name);
    }

    public static function deleteAll(): void {
        apcu_clear_cache();
    }
}