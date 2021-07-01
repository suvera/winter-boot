<?php
declare(strict_types=1);

namespace dev\winterframework\type;

use SplHeap;

class IntegerMinHeap extends SplHeap {

    protected function compare($value1, $value2): int {
        return $value2 - $value1;
    }
}