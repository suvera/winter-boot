<?php
declare(strict_types=1);

namespace dev\winterframework\exception;

use RuntimeException;
use Throwable;

class BeansException extends RuntimeException {

    public function __construct(string $beanName, $code = 0, Throwable $previous = null) {
        parent::__construct("Could not find/create Bean of type/name $beanName ", $code, $previous);
    }

}