<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\stereotype\Service;

#[Service("serviceX")]
class T0001Service {

    private int $value = 10;

    public function __construct() {
    }

    public function getValue(): int {
        return $this->value;
    }

}