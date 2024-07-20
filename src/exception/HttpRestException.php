<?php

declare(strict_types=1);

namespace dev\winterframework\exception;

use dev\winterframework\web\http\HttpStatus;

class HttpRestException extends WinterException {

    public function __construct(
        protected HttpStatus $status,
        string $message,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $status->getValue(), $previous);
    }

    public function getStatus(): HttpStatus {
        return $this->status;
    }
}
