<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class T0003Service {

    #[Autowired]
    private T0002Service $value;

    public function __construct() {
    }
}