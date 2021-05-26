<?php
declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;

interface ResponseRenderer {

    public function render(ResponseEntity $entity, HttpRequest $request): void;

    public function renderAndExit(ResponseEntity $entity, HttpRequest $request): void;

}