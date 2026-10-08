<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use Throwable;

/**
 * Single entry point for unserialize() on data read back from stores
 * (sessions, shared KV cache, shared queue).
 *
 * The allow-list comes from `winter.security.unserialize.allowedClasses`
 * (set at boot). Default `true` keeps 2.x behaviour (any class); a list
 * restricts objects to those classes, and `[]` allows none. Disallowed
 * objects come back as __PHP_Incomplete_Class instead of being built,
 * which blocks object-injection gadgets from a tampered store.
 */
final class SerializationUtil {

    /** @var bool|string[] */
    private static bool|array $allowedClasses = true;

    /** @param bool|string[] $allowed */
    public static function setAllowedClasses(bool|array $allowed): void {
        self::$allowedClasses = is_array($allowed) ? array_values($allowed) : $allowed;
    }

    /** @return bool|string[] */
    public static function getAllowedClasses(): bool|array {
        return self::$allowedClasses;
    }

    /**
     * @param bool $quiet return false instead of raising on corrupt input
     */
    public static function unserialize(string $data, bool $quiet = false): mixed {
        $options = ['allowed_classes' => self::$allowedClasses];
        if (!$quiet) {
            return unserialize($data, $options);
        }
        try {
            return @unserialize($data, $options);
        } catch (Throwable) {
            return false;
        }
    }
}
