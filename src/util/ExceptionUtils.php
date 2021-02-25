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

}