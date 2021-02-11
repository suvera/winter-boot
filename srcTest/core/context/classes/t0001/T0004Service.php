<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class T0004Service {

    #[Autowired]
    private T0004Service $value;

    public function __construct() {
    }
}