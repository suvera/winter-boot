<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\web\http\HttpRequest;
use winterBootTests\Support\TestCase;

/**
 * 2.3.0: getCookieHeader() rebuilds the Cookie header to call another
 * service as the same user; under Swoole the original header is not among
 * the request headers (getFirstHeader('Cookie') is null there).
 */
class HttpRequestCookieHeaderTest extends TestCase {

    private function request(array $cookies): HttpRequest {
        $saved = $_COOKIE;
        $_COOKIE = $cookies;
        try {
            return new HttpRequest();
        } finally {
            $_COOKIE = $saved;
        }
    }

    public function testRebuildsTheCookieHeader(): void {
        $this->assertSame('SESSID=abc123; theme=dark', $this->request(['SESSID' => 'abc123', 'theme' => 'dark'])->getCookieHeader());
        $this->assertSame('', $this->request([])->getCookieHeader());
    }

    public function testValuesAreEncodedSoTheyCannotSplitTheHeader(): void {
        $header = $this->request(['a' => "x; injected=1", 'b' => "line\r\nX-Evil: 1"])->getCookieHeader();
        $this->assertSame('a=x%3B%20injected%3D1; b=line%0D%0AX-Evil%3A%201', $header);
        $this->assertSame(false, str_contains($header, "\r") || str_contains($header, "\n"));
    }
}
