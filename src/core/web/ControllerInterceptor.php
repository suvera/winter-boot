<?php
declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use ReflectionMethod;

interface ControllerInterceptor {

    public function preHandle(HttpRequest $request, ResponseEntity $response, ReflectionMethod $handler): bool;

    public function postHandle(HttpRequest $request, ResponseEntity $response, ReflectionMethod $handler): void;
}