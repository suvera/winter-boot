<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\error;

use dev\winterframework\web\http\HttpStatus;
use Throwable;

interface ErrorController {

    public function handleError(HttpStatus $status, Throwable $t = null): void;
}