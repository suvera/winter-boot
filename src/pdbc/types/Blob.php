<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\types;

class Blob extends AbstractLob {

    public function getType(): int {
        return self::BLOB;
    }
}