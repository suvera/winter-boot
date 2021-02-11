<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\type\TypeAssert;

class DefaultLockManager implements LockManager {
    /**
     * @var Lock[]
     */
    private static array $allLocks = [];

    private static LockManager $instance;

    private function __construct() {
    }

    public static function get(): LockManager {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return Lock[]
     */
    public function getLocks(): array {
        return self::$allLocks;
    }

    public function createLock(string $name, string $provider): Lock {
        TypeAssert::objectOfIsA($provider, Lock::class);

        if (!isset(self::$allLocks[$name])) {
            $lock = new $provider($name);
            self::$allLocks[$name] = $lock;
        }

        return self::$allLocks[$name];
    }


}