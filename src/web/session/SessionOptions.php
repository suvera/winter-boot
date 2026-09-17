<?php

declare(strict_types=1);

namespace dev\winterframework\web\session;

/**
 * Session cookie value object.
 *
 * Carries every knob the framework needs to emit the session cookie
 * explicitly; nothing is read from php.ini here. Map ini values into this
 * object once at the application edge when legacy behaviour is wanted.
 */
class SessionOptions {
    public function __construct(
        public string $name = 'WINTERSESSID',
        public int $expirySecs = 0,
        public string $path = '/',
        public string $domain = '',
        public bool $secure = false,
        public bool $httponly = true
    ) {
    }
}
