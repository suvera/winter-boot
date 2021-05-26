<?php
declare(strict_types=1);

namespace dev\winterframework\web;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;

interface HttpRequestDispatcher {

    public function dispatch(HttpRequest $request = null, ResponseEntity $response = null): void;

}