<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

class MyInterceptor implements HandlerInterceptor {
    use Wlf4p;

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        self::logInfo(__METHOD__ . ' called ');
        //$response->setBody("Admin resource access denied!\n");
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
        self::logInfo(__METHOD__ . ' called ');
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, Throwable $ex = null): void {
        self::logInfo(__METHOD__ . ' called ');
    }

}