<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\SwooleRequest;

final class FakeSwooleHttpRequest extends \Swoole\Http\Request {
    public function getMethod(): string|false {
        return 'GET';
    }

    public function getContent(): string|false {
        return '';
    }
}

class SwooleRequestTest extends \winterBootTests\Support\TestCase {
    /** @param array<string,mixed> $server */
    private function swooleRequest(array $server, string $uri = '/x'): SwooleRequest {
        $raw = new FakeSwooleHttpRequest();
        $raw->get = [];
        $raw->post = [];
        $raw->cookie = [];
        $raw->server = array_merge(['request_uri' => $uri], $server);
        $raw->header = [];
        $raw->files = [];
        return new SwooleRequest($raw, new \Swoole\Http\Response());
    }

    public function testDoesNotWriteServerSuperglobal(): void {
        // The worker process is shared by concurrent coroutines: any
        // $_SERVER write here lets one request overwrite another's.
        $before = $_SERVER;
        $this->swooleRequest([
            'remote_addr' => '10.0.0.1',
            'remote_port' => 1234,
            'server_addr' => '10.0.0.2',
            'server_port' => 8080,
            'server_protocol' => 'HTTP/1.1',
            'query_string' => 'a=b',
            'request_time' => 1700000000,
            'request_time_float' => 1700000000.5,
        ]);
        $this->assertSame($before, $_SERVER);
    }

    public function testPerRequestServerValuesStayApart(): void {
        $first = $this->swooleRequest(
            ['remote_addr' => '10.0.0.1', 'remote_port' => 1111, 'query_string' => 'a=1'],
            '/first'
        );
        $second = $this->swooleRequest(
            ['remote_addr' => '10.0.0.2', 'remote_port' => 2222, 'query_string' => 'b=2'],
            '/second'
        );

        $this->assertSame('10.0.0.1', $first->getRemoteAddr());
        $this->assertSame(1111, $first->getRemotePort());
        $this->assertSame('a=1', $first->getQueryString());
        $this->assertSame('/first', $first->getUri());

        $this->assertSame('10.0.0.2', $second->getRemoteAddr());
        $this->assertSame(2222, $second->getRemotePort());
        $this->assertSame('b=2', $second->getQueryString());
        $this->assertSame('/second', $second->getUri());

        $this->assertSame(null, $first->getServerAddr());
    }

    public function testClassicRequestReadsSuperglobalOnce(): void {
        $saved = $_SERVER;
        try {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REMOTE_PORT'] = '9999';
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
            $req = new HttpRequest();
            $this->assertSame('127.0.0.1', $req->getRemoteAddr());
            $this->assertSame(9999, $req->getRemotePort());
            $this->assertSame('HTTP/1.1', $req->getServerProtocol());
        } finally {
            $_SERVER = $saved;
        }
    }
}
