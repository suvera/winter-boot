<?php
declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\web\http\ResponseEntity;

interface ResponseRenderer {

    public function render(ResponseEntity $entity): void;

    public function renderAndExit(ResponseEntity $entity): void;

}