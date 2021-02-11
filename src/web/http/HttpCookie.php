<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

class HttpCookie {
    public function __construct(
        public string $name,
        public string $value = '',
        public int $expires = 0,
        public string $path = '',
        public string $domain = '',
        public bool $secure = false,
        public bool $httponly = false
    ) {
    }
}