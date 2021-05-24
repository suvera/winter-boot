<?php
declare(strict_types=1);

namespace dev\winterframework\web;

use dev\winterframework\web\http\HttpRequest;

interface HttpRequestDispatcher {

    public function dispatch(HttpRequest $request = null): void;

}