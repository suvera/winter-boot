<?php
declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

interface HandlerInterceptor {

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool;

    public function postHandle(HttpRequest $request, ResponseEntity $response): void;

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, Throwable $ex = null): void;

}