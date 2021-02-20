<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\types;

class Clob extends AbstractLob {

    public function getType(): int {
        return self::CLOB;
    }
}