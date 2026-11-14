<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\web\client\DefaultRestClientTransport;
use dev\winterframework\web\client\HttpClientErrorException;
use dev\winterframework\web\client\HttpEntity;
use dev\winterframework\web\client\HttpServerErrorException;
use dev\winterframework\web\client\RestClientException;
use dev\winterframework\web\client\RestClientTimeoutException;
use dev\winterframework\web\client\RestClientTransport;
use dev\winterframework\web\client\RestTemplate;
use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\client\StreamDecoder;
use dev\winterframework\web\client\StreamRequest;
use dev\winterframework\web\client\StreamDecodeException;
use dev\winterframework\web\sse\SseEvent;
use dev\winterframework\web\sse\SseParser;
use dev\winterframework\web\client\stream\HttpResponseStreamParser;
use dev\winterframework\web\client\stream\HttpStreamClient;
use dev\winterframework\web\client\stream\StreamSocket;
use dev\winterframework\web\client\StreamingRestClientTransport;
use winterBootTests\Support\TestCase;

/** Streaming fake: replays a queued status, headers and body chunks. */
final class FakeStreamingRestClientTransport implements RestClientTransport, StreamingRestClientTransport {
    /** @var array<int, array{status: int, headers: array<string, string[]>, chunks: string[]}> */
    public array $queued = [];
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];
    /** Chunks actually handed to the consumer, per call. */
    public int $delivered = 0;

    /** @param string[] $chunks */
    public function queue(int $status, array $chunks, array $headers = []): void {
        $this->queued[] = ['status' => $status, 'headers' => $headers, 'chunks' => $chunks];
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): array {
        throw new \LogicException('request() must not be used for streaming');
    }

    public function stream(StreamRequest $request, callable $onResponse, callable $onChunk): void {
        $this->calls[] = [
            'method' => $request->method,
            'url' => $request->url,
            'headers' => $request->headers,
            'body' => $request->body,
            'idleTimeout' => $request->idleTimeout,
            'connectTimeout' => $request->connectTimeout,
        ];
        $next = array_shift($this->queued);
        $onResponse($next['status'], $next['headers']);
        $this->delivered = 0;
        foreach ($next['chunks'] as $chunk) {
            $this->delivered++;
            if ($onChunk($chunk) === false) {
                return;
            }
        }
    }
}

/** Plain transport without streaming support (fallback path). */
final class FakeWholeBodyTransport implements RestClientTransport {
    public function __construct(private int $status, private string $body) {
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): array {
        return ['status' => $this->status, 'headers' => ['Content-Type' => ['text/plain']], 'body' => $this->body];
    }
}

/** Scripted socket: replays reads (string, '' = EOF, null = idle timeout) and records the conversation. */
final class ScriptedStreamSocket implements StreamSocket {
    /** @var array<int, string|null> */
    public array $reads;
    public string $written = '';
    /** @var string[] */
    public array $log = [];
    public bool $closed = false;

    /** @param array<int, string|null> $reads */
    public function __construct(array $reads) {
        $this->reads = $reads;
    }

    public function connect(string $host, int $port, float $timeout): void {
        $this->log[] = "connect $host:$port";
    }

    public function enableTls(string $peerName, bool $verify, ?string $caFile, float $timeout): void {
        $this->log[] = 'tls ' . $peerName . ' verify=' . ($verify ? '1' : '0');
    }

    public function send(string $data): void {
        $this->written .= $data;
        $this->log[] = 'send ' . strtok($data, "\r\n");
    }

    /** Called before every read with the read count (e.g. to cancel mid-stream). */
    public ?\Closure $onRecv = null;
    public int $recvCalls = 0;

    public function recv(float $timeout): ?string {
        $this->recvCalls++;
        if ($this->onRecv !== null) {
            ($this->onRecv)($this->recvCalls);
        }
        if ($this->reads === []) {
            return '';
        }
        return array_shift($this->reads);
    }

    public function close(): void {
        $this->closed = true;
    }
}

/**
 * RestTemplate::exchangeStream(), the incremental HTTP/1.1 response parser
 * behind the default transport, and the SSE parser.
 */
final class RestTemplateStreamTest extends TestCase {

    // ---- RestTemplate::exchangeStream ----------------------------------

    public function testChunksArriveInOrder(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ['a', 'b', 'c'], ['Content-Type' => ['text/plain']]);
        $got = [];
        $res = (new RestTemplate($t))->exchangeStream('post', 'https://ai.example/v1/{m}', new HttpEntity(['q' => 1]),
            function (string $chunk) use (&$got) {
                $got[] = $chunk;
            }, uriVars: ['m' => 'chat']);
        $this->assertSame(['a', 'b', 'c'], $got);
        $this->assertSame(200, $res->getStatus()->getValue());
        $this->assertFalse($res->isAborted());
        $this->assertSame(3, $res->getBytesReceived());
        $this->assertSame('text/plain', $res->getHeaders()->getFirst('Content-Type'));
        $this->assertSame('POST', $t->calls[0]['method']);
        $this->assertSame('https://ai.example/v1/chat', $t->calls[0]['url']);
        $this->assertSame('{"q":1}', $t->calls[0]['body']);
    }

    public function testReturningFalseAbortsWithoutError(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ['one', 'two', 'three']);
        $got = [];
        $res = (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null, function (string $c) use (&$got) {
            $got[] = $c;
            return $c !== 'two';
        });
        $this->assertSame(['one', 'two'], $got);
        $this->assertSame(2, $t->delivered, 'transport stops after the false');
        $this->assertTrue($res->isAborted());
    }

    public function testStreamIdleTimeoutIsSeparateFromTheTotalTimeout(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, []);
        $t->queue(200, []);
        $rest = new RestTemplate($t, 7.5, 2.5);
        $rest->exchangeStream('GET', 'http://h/s', null, fn() => true);
        $this->assertSame(RestTemplate::DEFAULT_STREAM_IDLE_TIMEOUT, $t->calls[0]['idleTimeout'], 'not the 7.5 s total timeout');
        $this->assertSame(2.5, $t->calls[0]['connectTimeout']);
        $rest->setStreamIdleTimeout(90.0);
        $rest->exchangeStream('GET', 'http://h/s', null, fn() => true);
        $this->assertSame(90.0, $t->calls[1]['idleTimeout']);
        $this->assertSame(90.0, $rest->getStreamIdleTimeout());
    }

    public function testClientErrorThrowsWithBodyAndNeverCallsOnChunk(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(429, ['{"error":', '"slow down"}']);
        $called = false;
        try {
            (new RestTemplate($t))->exchangeStream('POST', 'https://ai.example/v1?key=SECRET', null,
                function () use (&$called) {
                    $called = true;
                });
            throw new \RuntimeException('expected exception');
        } catch (HttpClientErrorException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('{"error":"slow down"}', $e->getResponseBody());
            $this->assertFalse(str_contains($e->getMessage(), 'SECRET'), 'query never in message');
        }
        $this->assertFalse($called);
    }

    public function testServerErrorThrows(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(503, ['down']);
        $this->assertThrows(HttpServerErrorException::class,
            fn() => (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null, fn() => true));
    }

    public function testErrorWithThrowDisabledReturnsRawBody(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(404, ['nope']);
        $rest = new RestTemplate($t);
        $rest->setThrowOnError(false);
        $res = $rest->exchangeStream('GET', 'http://h/s', null, fn() => true);
        $this->assertSame(404, $res->getStatus()->getValue());
        $this->assertSame('nope', $res->getBody());
    }

    public function testErrorBodyIsCappedAndReadingStops(): void {
        $t = new FakeStreamingRestClientTransport();
        $big = str_repeat('x', RestTemplate::STREAM_ERROR_BODY_MAX);
        $t->queue(500, [$big, 'more', 'more']);
        try {
            (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null, fn() => true);
        } catch (HttpServerErrorException $e) {
            $this->assertSame(RestTemplate::STREAM_ERROR_BODY_MAX, strlen($e->getResponseBody()));
        }
        $this->assertSame(1, $t->delivered);
    }

    public function testNonStreamingTransportDeliversOneChunk(): void {
        $got = [];
        $res = (new RestTemplate(new FakeWholeBodyTransport(200, 'whole')))
            ->exchangeStream('GET', 'http://h/s', null, function (string $c) use (&$got) {
                $got[] = $c;
            });
        $this->assertSame(['whole'], $got);
        $this->assertSame(200, $res->getStatus()->getValue());
        $this->assertThrows(HttpClientErrorException::class,
            fn() => (new RestTemplate(new FakeWholeBodyTransport(401, 'no')))
                ->exchangeStream('GET', 'http://h/s', null, fn() => true));
    }

    public function testSseAdapterDeliversEventsAndStopsAtDone(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["data: {\"t\":\"Hel", "lo\"}\n\nevent: progress\ndata: 50\n", "\ndata: [DONE]\n\ndata: late\n\n"]);
        $events = [];
        (new RestTemplate($t))->exchangeStream('POST', 'http://h/s', null,
            function (SseEvent $e) use (&$events) {
                $events[] = $e->event . '=' . $e->data;
            }, decoder: StreamDecoder::sse());
        $this->assertSame(['message={"t":"Hello"}', 'progress=50'], $events);
    }

    public function testSseAdapterAbortsWhenHandlerReturnsFalse(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["data: 1\n\n", "data: 2\n\n", "data: 3\n\n"]);
        $seen = 0;
        $res = (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null,
            function () use (&$seen) {
                return ++$seen < 2;
            }, decoder: StreamDecoder::sse());
        $this->assertSame(2, $seen);
        $this->assertTrue($res->isAborted());
    }

    public function testDefaultTransportRejectsBadSchemeAndCrlfBeforeNetwork(): void {
        $tr = new DefaultRestClientTransport();
        $noop = fn() => true;
        $this->assertThrows(RestClientException::class,
            fn() => $tr->stream(new StreamRequest('GET', 'file:///etc/passwd', [], null, 1, 1), fn() => null, $noop));
        $this->assertThrows(RestClientException::class,
            fn() => $tr->stream(new StreamRequest('GET', 'http://h/', ['X' => "a\r\nB: c"], null, 1, 1), fn() => null, $noop));
    }

    // ---- HttpStreamClient over a scripted socket ------------------------

    private function runClient(ScriptedStreamSocket $socket, string $url, array &$chunks, ?string $proxy = null, string $method = 'POST', ?string $body = '{"a":1}', array $headers = ['Accept-Encoding' => 'gzip', 'X-Api' => 'k']): array {
        $head = [];
        $client = new HttpStreamClient($socket, true, null, $proxy);
        $client->execute($method, $url, parse_url($url), $headers, $body, 1.0, 1.0,
            function (int $s, array $h) use (&$head) {
                $head = [$s, $h];
            },
            function (string $c) use (&$chunks) {
                $chunks[] = $c;
                return $c !== 'STOP';
            });
        return $head;
    }

    public function testStreamClientWritesRequestAndDecodesChunks(): void {
        $socket = new ScriptedStreamSocket([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: text/event-stream\r\n\r\n5\r\nhel",
            "lo\r\n6;ext=1\r\n world\r\n0\r\n\r\n",
        ]);
        $chunks = [];
        $head = $this->runClient($socket, 'https://api.example:8443/v1/chat?x=1', $chunks);
        $this->assertSame(200, $head[0]);
        $this->assertSame('hello world', implode('', $chunks));
        $this->assertSame(['connect api.example:8443', 'tls api.example verify=1', 'send POST /v1/chat?x=1 HTTP/1.1'], $socket->log);
        $this->assertTrue(str_contains($socket->written, "Host: api.example:8443\r\n"));
        $this->assertTrue(str_contains($socket->written, "Accept-Encoding: identity\r\n"));
        $this->assertFalse(str_contains($socket->written, 'gzip'), 'caller Accept-Encoding replaced');
        $this->assertTrue(str_contains($socket->written, "Connection: close\r\n"));
        $this->assertTrue(str_contains($socket->written, "Content-Length: 7\r\n\r\n{\"a\":1}"));
        $this->assertTrue(str_contains($socket->written, "X-Api: k\r\n"));
        $this->assertTrue($socket->closed);
    }

    public function testStreamClientIdleTimeoutThrowsTimeoutWithoutQuery(): void {
        $socket = new ScriptedStreamSocket(["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nhi\r\n", null]);
        $chunks = [];
        try {
            $this->runClient($socket, 'http://h/s?token=SECRET', $chunks);
            throw new \RuntimeException('expected timeout');
        } catch (RestClientTimeoutException $e) {
            $this->assertFalse(str_contains($e->getMessage(), 'SECRET'));
            $this->assertTrue(str_contains($e->getMessage(), 'http://h/s'));
        }
        $this->assertSame(['hi'], $chunks);
        $this->assertTrue($socket->closed);
    }

    public function testStreamClientAbortStopsReadingAndCloses(): void {
        $socket = new ScriptedStreamSocket([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nSTOP\r\n",
            "4\r\nnext\r\n0\r\n\r\n",
        ]);
        $chunks = [];
        $this->runClient($socket, 'http://h/s', $chunks);
        $this->assertSame(['STOP'], $chunks);
        $this->assertSame(1, count($socket->reads), 'second read never happened');
        $this->assertTrue($socket->closed);
    }

    public function testStreamClientTunnelsHttpsThroughProxy(): void {
        $socket = new ScriptedStreamSocket([
            "HTTP/1.1 200 Connection established\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
        ]);
        $chunks = [];
        $this->runClient($socket, 'https://api.example/v1', $chunks, 'http://user:p%40ss@proxy.local:3128', 'GET', null);
        $this->assertSame([
            'connect proxy.local:3128',
            'send CONNECT api.example:443 HTTP/1.1',
            'tls api.example verify=1',
            'send GET /v1 HTTP/1.1',
        ], $socket->log);
        $this->assertTrue(str_contains($socket->written, 'Proxy-Authorization: Basic ' . base64_encode('user:p@ss')));
        $this->assertSame(['ok'], $chunks);
    }

    public function testStreamClientSendsAbsoluteFormForPlainHttpProxy(): void {
        $socket = new ScriptedStreamSocket(["HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx"]);
        $chunks = [];
        $this->runClient($socket, 'http://api.example:8080/v1?q=1', $chunks, 'http://proxy.local:3128', 'GET', null);
        $this->assertSame(['connect proxy.local:3128', 'send GET http://api.example:8080/v1?q=1 HTTP/1.1'], $socket->log);
        $this->assertFalse(str_contains($socket->written, 'Proxy-Authorization'));
    }

    public function testStreamClientRefusedConnectFails(): void {
        $socket = new ScriptedStreamSocket(["HTTP/1.1 407 Proxy Authentication Required\r\n\r\n"]);
        $chunks = [];
        $this->assertThrows(RestClientException::class,
            fn() => $this->runClient($socket, 'https://api.example/v1', $chunks, 'http://proxy.local:3128', 'GET', null));
        $this->assertSame([], $chunks);
        $this->assertTrue($socket->closed);
    }

    public function testOnlyHttpProxiesAccepted(): void {
        $this->assertThrows(RestClientException::class, fn() => HttpStreamClient::parseProxy('socks5://p:1080'));
        $this->assertSame(['host' => 'p', 'port' => 8080, 'auth' => null], HttpStreamClient::parseProxy('p'));
    }

    // ---- HttpResponseStreamParser ---------------------------------------

    /** @return array{0: int, 1: array<string, string[]>, 2: string, 3: bool} status, headers, body, complete */
    private function parseSplit(string $wire, int $split, bool $head = false, bool $eof = true): array {
        $status = 0;
        $headers = [];
        $body = '';
        $p = new HttpResponseStreamParser(
            function (int $s, array $h) use (&$status, &$headers) {
                $status = $s;
                $headers = $h;
            },
            function (string $b) use (&$body) {
                $body .= $b;
                return true;
            },
            $head
        );
        foreach ($split > 0 ? str_split($wire, $split) : [$wire] as $piece) {
            $p->feed($piece);
        }
        if ($eof) {
            $p->finish();
        }
        return [$status, $headers, $body, $p->isComplete()];
    }

    public function testChunkedSplitAtEveryBoundary(): void {
        $wire = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nX-A: 1\r\n\r\n"
            . "3\r\nabc\r\n1A;name=val\r\n" . str_repeat('z', 26) . "\r\n0\r\nTrailer: t\r\n\r\n";
        for ($i = 1; $i <= strlen($wire); $i++) {
            [$status, $headers, $body, $done] = $this->parseSplit($wire, $i, false, false);
            $this->assertSame(200, $status, "split $i");
            $this->assertSame('abc' . str_repeat('z', 26), $body, "split $i");
            $this->assertTrue($done, "split $i");
            $this->assertSame(['1'], $headers['X-A']);
        }
    }

    public function testContentLengthStopsAtLength(): void {
        $wire = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhelloEXTRA";
        foreach ([1, 3, 0] as $split) {
            [, , $body, $done] = $this->parseSplit($wire, $split, false, false);
            $this->assertSame('hello', $body);
            $this->assertTrue($done);
        }
    }

    public function testCloseDelimitedBodyEndsAtEof(): void {
        [$status, , $body, $done] = $this->parseSplit("HTTP/1.0 200 OK\r\n\r\nline1\nline2", 4);
        $this->assertSame(200, $status);
        $this->assertSame("line1\nline2", $body);
        $this->assertTrue($done);
    }

    public function testInterimResponsesAreSkipped(): void {
        [$status, , $body] = $this->parseSplit(
            "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </a>\r\n\r\nHTTP/1.1 201 Created\r\nContent-Length: 2\r\n\r\nok", 5);
        $this->assertSame(201, $status);
        $this->assertSame('ok', $body);
    }

    public function testBodylessResponses(): void {
        [, , $body, $done] = $this->parseSplit("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\n", 0, true, false);
        $this->assertSame('', $body);
        $this->assertTrue($done, 'HEAD has no body');
        [, , , $done] = $this->parseSplit("HTTP/1.1 204 No Content\r\n\r\n", 0, false, false);
        $this->assertTrue($done);
    }

    public function testHeaderBlockIsCapped(): void {
        $p = new HttpResponseStreamParser(fn() => null, fn() => true, false, 1024);
        $this->assertThrows(RestClientException::class,
            fn() => $p->feed("HTTP/1.1 200 OK\r\nX-Big: " . str_repeat('a', 2000)));
    }

    public function testTruncatedAndMalformedResponsesFail(): void {
        $this->assertThrows(RestClientException::class,
            fn() => $this->parseSplit("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort", 0));
        $this->assertThrows(RestClientException::class,
            fn() => $this->parseSplit("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\nZZ\r\n", 0));
        $this->assertThrows(RestClientException::class,
            fn() => $this->parseSplit("garbage\r\n\r\n", 0));
        $this->assertThrows(RestClientException::class,
            fn() => $this->parseSplit("HTTP/1.1 200", 0));
    }

    public function testGzipBodyIsDecodedIncrementally(): void {
        $plain = str_repeat("data: token\n\n", 200);
        $gz = gzencode($plain);
        $wire = "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen($gz) . "\r\n\r\n" . $gz;
        [, $headers, $body, $done] = $this->parseSplit($wire, 7, false, false);
        $this->assertSame($plain, $body);
        $this->assertTrue($done);
        $this->assertFalse(isset($headers['Content-Encoding']), 'decoded: encoding header dropped');
    }

    // ---- SseParser --------------------------------------------------------

    /** @return string[] */
    private function sseSplit(string $stream, int $split): array {
        $p = new SseParser();
        $out = [];
        foreach ($split > 0 ? str_split($stream, $split) : [$stream] as $piece) {
            foreach ($p->feed($piece) as $e) {
                $out[] = $e->event . '|' . $e->data . '|' . ($e->id ?? '-') . '|' . ($e->retry ?? '-');
            }
        }
        return $out;
    }

    public function testSseSplitAtEveryBoundary(): void {
        $stream = "\xEF\xBB\xBF: keep-alive comment\r\n"
            . "event: delta\r\ndata: {\"t\":\"Hé\"}\r\n\r\n"
            . "data: line1\ndata:line2\ndata\nid: 7\n\n"
            . "retry: 3000\rdata: cr-only\r\r"
            . "event: noise\n\n"
            . "unknown: x\ndata: [DONE]\n\n";
        $expected = [
            'delta|{"t":"Hé"}|-|-',
            "message|line1\nline2\n|7|-",
            'message|cr-only|7|3000',
            'message|[DONE]|7|-',
        ];
        for ($i = 0; $i <= strlen($stream); $i++) {
            $this->assertSame($expected, $this->sseSplit($stream, $i), "split $i");
        }
    }

    public function testSseEventHelpers(): void {
        $e = new SseEvent('message', '{"choices":[{"delta":{"content":"Hi"}}]}');
        $this->assertSame('Hi', $e->json()['choices'][0]['delta']['content']);
        $this->assertFalse($e->isDone());
        $this->assertTrue((new SseEvent('message', '[DONE]'))->isDone());
        $this->assertThrows(\JsonException::class, fn() => (new SseEvent('message', '{bad'))->json());
    }

    public function testSseIncompleteEventOnlyOnRequest(): void {
        $p = new SseParser();
        $this->assertSame([], $p->feed("data: tail"));
        $this->assertSame([], (clone $p)->finish());
        $events = $p->finish(true);
        $this->assertSame('tail', $events[0]->data);
    }

    public function testSsePendingDataIsCapped(): void {
        $p = new SseParser(64);
        $this->assertThrows(\dev\winterframework\web\sse\SseException::class, fn() => $p->feed(str_repeat('a', 100)));
    }

    public function testRequestLineCannotBeSplit(): void {
        $parts = parse_url('http://api.example.com/v1/chat?x=1');
        $ok = \dev\winterframework\web\client\stream\HttpStreamClient::buildRequest('POST', $parts, 'api.example.com', 80, false, [], '{}');
        $this->assertTrue(str_starts_with($ok, "POST /v1/chat?x=1 HTTP/1.1\r\nHost: api.example.com\r\n"));
        $this->assertThrows(RestClientException::class, fn() => \dev\winterframework\web\client\stream\HttpStreamClient::buildRequest(
            "GET / HTTP/1.1\r\nX-Evil: 1\r\n", $parts, 'api.example.com', 80, false, [], null
        ));
        $this->assertThrows(RestClientException::class, fn() => \dev\winterframework\web\client\stream\HttpStreamClient::buildRequest(
            'GET', ['path' => '/a b'], 'api.example.com', 80, false, [], null
        ));
    }

    // ---- 2.3.0 design: decoders, cancellation, result ------------------

    public function testNdjsonDecoderJoinsLinesSplitAcrossChunks(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["{\"m\":\"He", "llo\"}\r\n\n{\"m\":", "\"wor\"}\n{\"done\":true}"]);
        $lines = [];
        $res = (new RestTemplate($t))->exchangeStream('POST', 'http://h/api/generate', null,
            function (array $line) use (&$lines) {
                $lines[] = $line;
            }, decoder: StreamDecoder::ndjson());
        $this->assertSame([['m' => 'Hello'], ['m' => 'wor'], ['done' => true]], $lines, 'last line without newline delivered at the end');
        $this->assertTrue($res->isCompleted());
        $this->assertSame(3, $res->getItemsDelivered());
    }

    public function testDecodeFailureIsAStreamDecodeExceptionWithoutBodyContent(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["{\"secret\":\"tok-123\"\n"]);
        try {
            (new RestTemplate($t))->exchangeStream('GET', 'http://h/s?key=K', null, fn() => true, decoder: StreamDecoder::ndjson());
            throw new \RuntimeException('expected StreamDecodeException');
        } catch (StreamDecodeException $e) {
            $this->assertFalse(str_contains($e->getMessage(), 'tok-123'), 'no body content');
            $this->assertFalse(str_contains($e->getMessage(), 'key=K'), 'no query');
            $this->assertTrue($e->getPrevious() instanceof \JsonException);
        }
        $t->queue(200, [str_repeat('x', 40)]);
        $this->assertThrows(StreamDecodeException::class, fn() => (new RestTemplate($t))
            ->exchangeStream('GET', 'http://h/s', null, fn() => true, decoder: StreamDecoder::ndjson(16)));
    }

    public function testCallbackExceptionsPropagateUnwrapped(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["data: 1\n\n"]);
        $this->assertThrows(\DomainException::class, fn() => (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null,
            function () {
                throw new \DomainException('app failure');
            }, decoder: StreamDecoder::sse()));
    }

    public function testSseDecoderCompletesAtDoneAndKeepsLastEventId(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ["id: 7\ndata: a\n\n", "id: 8\ndata: [DONE]\n\n", "data: never-read\n\n"]);
        $sse = StreamDecoder::sse();
        $seen = [];
        $res = (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null,
            function (SseEvent $e) use (&$seen) {
                $seen[] = $e->data;
            }, decoder: $sse);
        $this->assertSame(['a'], $seen);
        $this->assertTrue($res->isCompleted());
        $this->assertFalse($res->isAborted());
        $this->assertSame(2, $t->delivered, 'reading stopped at [DONE]');
        $this->assertSame('8', $sse->getLastEventId());
    }

    public function testCancelBeforeTheCallMakesNoRequest(): void {
        $t = new FakeStreamingRestClientTransport();
        $token = new CancellationToken();
        $token->cancel();
        $res = (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null, fn() => true, cancel: $token);
        $this->assertTrue($res->isCancelled());
        $this->assertSame([], $t->calls, 'no request sent');
    }

    public function testCancelDuringTheStreamStopsItWithoutError(): void {
        $t = new FakeStreamingRestClientTransport();
        $t->queue(200, ['a', 'b', 'c', 'd']);
        $token = new CancellationToken();
        $got = [];
        $res = (new RestTemplate($t))->exchangeStream('GET', 'http://h/s', null,
            function (string $c) use (&$got, $token) {
                $got[] = $c;
                if ($c === 'b') {
                    $token->cancel('stop');
                }
            }, cancel: $token);
        $this->assertSame(['a', 'b'], $got);
        $this->assertTrue($res->isCancelled());
        $this->assertFalse($res->isAborted());
        $this->assertFalse($res->isCompleted());
    }

    public function testStreamClientStopsPromptlyWhileTheServerIsSilent(): void {
        // Headers and one chunk, then silence (each null is an empty wait slice).
        $socket = new ScriptedStreamSocket(array_merge(
            ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nhi\r\n"],
            array_fill(0, 50, null)
        ));
        $token = new CancellationToken();
        $socket->onRecv = function (int $n) use ($token): void {
            if ($n === 4) {
                $token->cancel();
            }
        };
        $chunks = [];
        $client = new HttpStreamClient($socket, true, null, null);
        $client->execute('GET', 'http://h/s', parse_url('http://h/s'), [], null, 30.0, 1.0,
            fn() => null, function (string $c) use (&$chunks) {
                $chunks[] = $c;
                return true;
            }, $token);
        $this->assertSame(['hi'], $chunks);
        $this->assertSame(4, $socket->recvCalls, 'stopped at the first check after cancel(), long before the 30 s idle timeout');
        $this->assertTrue($socket->closed);
    }

    public function testWholeBodyTransportGoesThroughTheDecoder(): void {
        $lines = [];
        $res = (new RestTemplate(new FakeWholeBodyTransport(200, "{\"a\":1}\n{\"a\":2}\n")))
            ->exchangeStream('GET', 'http://h/s', null, function (array $l) use (&$lines) {
                $lines[] = $l['a'];
            }, decoder: StreamDecoder::ndjson());
        $this->assertSame([1, 2], $lines);
        $this->assertTrue($res->isCompleted());
    }
}
