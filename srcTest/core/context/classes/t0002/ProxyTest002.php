<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0002;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class ProxyTest002 {

    #[Autowired]
    private ProxyTest001 $test;
}