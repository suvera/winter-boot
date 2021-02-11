<?php
declare(strict_types=1);

namespace dev\winterframework\web;

interface HttpRequestDispatcher {

    public function dispatch(): void;
    
}