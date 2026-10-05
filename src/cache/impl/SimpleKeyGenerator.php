<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\stereotype\aop\AopContext;
use Throwable;

/**
 * Default cache key: "Class::method_" + arguments.
 *
 * Every argument contributes its value (arrays and objects included), so
 * different arguments never share a key; keys longer than 255 chars keep a
 * readable prefix plus a hash of the full key.
 */
class SimpleKeyGenerator implements KeyGenerator {

    private const MAX_LENGTH = 255;

    public function generate(AopContext $ctx, object $obj, array $args): string {
        $method = $ctx->getMethod();
        $prefix = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (count($args) == 0) {
            return self::fit($prefix);
        }

        return self::fit($prefix . '_' . $this->argumentsKey($args));
    }

    protected function argumentsKey(array $args): string {
        $key = '';
        foreach ($args as $arg) {
            $key .= self::argumentKey($arg) . '-';
        }
        return $key;
    }

    private static function argumentKey(mixed $arg): string {
        if (is_scalar($arg)) {
            return (string)json_encode($arg);
        }
        if (is_null($arg)) {
            return 'null';
        }
        if (is_object($arg) && method_exists($arg, '__toString')) {
            return (string)json_encode($arg->__toString());
        }
        if (is_array($arg) || is_object($arg)) {
            // serialize() covers private/protected state that json_encode() drops.
            try {
                $encoded = serialize($arg);
            } catch (Throwable) {
                // Unserializable (e.g. closures): unique per call, i.e. never cached.
                $encoded = bin2hex(random_bytes(16));
            }
            $type = is_object($arg) ? $arg::class : 'array';
            return $type . ':' . hash('xxh128', $encoded);
        }
        return gettype($arg);
    }

    private static function fit(string $key): string {
        if (strlen($key) <= self::MAX_LENGTH) {
            return $key;
        }
        $hash = hash('xxh128', $key);
        return substr($key, 0, self::MAX_LENGTH - strlen($hash) - 1) . '#' . $hash;
    }
}
