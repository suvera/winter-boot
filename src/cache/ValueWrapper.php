<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

interface ValueWrapper {

    public function get(): mixed;
}