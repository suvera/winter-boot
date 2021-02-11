<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\stereotype\Service;
use dev\winterframework\util\log\Wlf4p;

#[Service]
class ApplicationLogger {
    use Wlf4p;
}