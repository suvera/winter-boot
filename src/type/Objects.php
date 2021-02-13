<?php
declare(strict_types=1);

namespace dev\winterframework\type;

class Objects {
    public static function hash(object $obj): string {
        return spl_object_hash($obj);
    }
}