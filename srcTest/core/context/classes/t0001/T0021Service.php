<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class T0021Service {

    public int $value = 10;

    #[Autowired]
    private T0022Service $value2;

    #[Autowired]
    private T0023Service $value3;

    #[Autowired]
    private T0024Service $value4;

    public function __construct() {
    }

    public function sum(): int {
        return $this->value + $this->value2->value + $this->value3->value + $this->value4->value;
    }
}