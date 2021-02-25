<?php
declare(strict_types=1);

namespace dev\winterframework\type;

class StringSet extends StringList {

    public function offsetSet($offset, $value): void {
        $offset = $value;
        parent::offsetSet($offset, $value);
    }
}