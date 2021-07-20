<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use Throwable;

class ExceptionUtils {

    public static function containsException(Throwable $tree, string $exception): bool {
        if (is_a($tree, $exception)) {
            return true;
        }

        $prev = $tree->getPrevious();
        if ($prev != null) {
            return self::containsException($prev, $exception);
        }

        return false;
    }

    public static function inExceptions(Throwable $exception, array $exceptionList): bool {
        if (empty($exceptionList)) {
            return false;
        }
        
        $objects = [$exception];
        $prev = $exception;
        do {
            $prev = $prev->getPrevious();
            if ($prev == null) {
                break;
            }
            $objects[] = $prev;
        } while (1);

        foreach ($exceptionList as $exCls) {
            foreach ($objects as $exObj) {
                if ($exObj instanceof $exCls) {
                    return true;
                }
            }
        }
        return false;
    }

}