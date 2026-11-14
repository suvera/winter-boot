<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use dev\winterframework\web\client\DefaultRestClientTransport;
use dev\winterframework\web\client\HttpClientErrorException;
use dev\winterframework\web\client\RestClientException;
use dev\winterframework\web\client\RestClientTimeoutException;
use dev\winterframework\web\client\RestTemplate;
use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\client\StreamDecoder;
use dev\winterframework\web\sse\SseEvent;
use dev\winterframework\web\client\stream\CoroutineStreamSocket;
use dev\winterframework\web\client\stream\HttpStreamClient;
use dev\winterframework\web\client\stream\PhpStreamSocket;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

/**
 * RestTemplate::exchangeStream() against real local servers, on both
 * engines: Swoole's coroutine socket (inside Co\run, no runtime hooks) and
 * PHP stream sockets (outside any coroutine). Covers incremental delivery,
 * the idle read timeout, abort, error mapping, an HTTP proxy (CONNECT and
 * absolute-form) and TLS verification failing closed.
 */
class RestTemplateStreamSwooleTest {
    private string $dir = '';
    private int $port = 0;
    /** @var resource|null */
    private $server = null;

    private function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \Exception($message);
        }
    }

    // ---- tests ---------------------------------------------------------------

    public function testChunksArriveIncrementallyOnBothEngines(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $times = [];
                $data = [];
                $start = microtime(true);
                $res = (new RestTemplate(null, 5.0))->exchangeStream('GET', $this->url('/sse?k=1'), null,
                    function (SseEvent $e) use (&$times, &$data, $start): void {
                        $times[] = microtime(true) - $start;
                        $data[] = $e->data;
                    }, decoder: StreamDecoder::sse());
                $this->check($res->getStatus()->getValue() === 200, "$engine: status");
                $this->check($data === ['0', '1', '2', '3', '4'], "$engine: events " . json_encode($data));
                $this->check($times[0] < 0.4, "$engine: first event late ({$times[0]})");
                $this->check($times[4] - $times[0] > 0.6, "$engine: events not spread out (" . json_encode($times) . ')');
            });
        });
    }

    public function testIdleTimeoutIsBetweenChunksNotTotal(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $rest = new RestTemplate(null, 0.5);
                $rest->setStreamIdleTimeout(0.5);
                // 10 chunks 0.15 s apart: 1.5 s total, never idle for 0.5 s.
                $n = 0;
                $rest->exchangeStream('GET', $this->url('/steady'), null, function () use (&$n): void {
                    $n++;
                });
                $this->check($n === 10, "$engine: steady stream got $n chunks");

                $got = [];
                $start = microtime(true);
                try {
                    $rest->exchangeStream('GET', $this->url('/gap?token=SECRET'), null, function (string $c) use (&$got): void {
                        $got[] = $c;
                    });
                    $this->check(false, "$engine: expected an idle timeout");
                } catch (RestClientTimeoutException $e) {
                    $this->check(!str_contains($e->getMessage(), 'SECRET'), "$engine: query leaked into message");
                }
                $elapsed = microtime(true) - $start;
                $this->check($got === ['first'], "$engine: chunk before the gap");
                $this->check($elapsed < 1.2, "$engine: timeout took $elapsed s");
            });
        });
    }

    /**
     * The "stop" button while the model is thinking: the server is silent,
     * another coroutine cancels, and the call returns at once instead of
     * waiting for the next byte or the idle timeout.
     */
    public function testCancelStopsASilentStreamPromptly(): void {
        $this->withServer(function (): void {
            Coroutine::set(['hook_flags' => 0]);
            $error = null;
            run(function () use (&$error): void {
                try {
                    $token = new CancellationToken();
                    Coroutine::create(function () use ($token): void {
                        Coroutine::sleep(0.3);
                        $token->cancel('stop');
                    });
                    $got = [];
                    $start = microtime(true);
                    $rest = new RestTemplate(null, 10.0);
                    $rest->setStreamIdleTimeout(10.0);
                    $res = $rest->exchangeStream('GET', $this->url('/gap'), null,
                        function (string $c) use (&$got): void {
                            $got[] = $c;
                        }, cancel: $token);
                    $elapsed = microtime(true) - $start;
                    $this->check($got === ['first'], 'chunk before the silence: ' . json_encode($got));
                    $this->check($res->isCancelled() && !$res->isCompleted(), 'reported as cancelled');
                    $this->check($elapsed < 0.7, "returned promptly after cancel(): {$elapsed}s");
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
            if ($error !== null) {
                throw $error;
            }
        });
    }

    public function testAbortClosesTheConnectionPromptly(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                @unlink($this->dir . '/abort.log');
                $n = 0;
                $start = microtime(true);
                $res = (new RestTemplate(null, 5.0))->exchangeStream('GET', $this->url('/endless'), null,
                    function () use (&$n): bool {
                        return ++$n < 3;
                    });
                $elapsed = microtime(true) - $start;
                $this->check($res->isAborted(), "$engine: not aborted");
                $this->check($n === 3, "$engine: chunks after abort: $n");
                $this->check($elapsed < 1.0, "$engine: abort took $elapsed s");
                $this->sleep(0.6);
                $log = (string)@file_get_contents($this->dir . '/abort.log');
                $this->check(str_contains($log, 'client gone'), "$engine: server kept writing: $log");
            });
        });
    }

    public function testErrorStatusMapsToExceptionWithBody(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $called = false;
                try {
                    (new RestTemplate(null, 5.0))->exchangeStream('POST', $this->url('/err'), null,
                        function () use (&$called): void {
                            $called = true;
                        });
                    $this->check(false, "$engine: expected HttpClientErrorException");
                } catch (HttpClientErrorException $e) {
                    $this->check($e->getStatusCode() === 429, "$engine: status");
                    $this->check($e->getResponseBody() === '{"error":"rate limited"}', "$engine: body " . $e->getResponseBody());
                }
                $this->check(!$called, "$engine: onChunk called for an error response");
            });
        });
    }

    public function testRequestBodyAndHeadersReachServer(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $body = '';
                (new RestTemplate(null, 5.0))->exchangeStream('POST', $this->url('/echo'),
                    new \dev\winterframework\web\client\HttpEntity(['prompt' => 'hi'], ['X-Api-Key' => 'k1']),
                    function (string $c) use (&$body): void {
                        $body .= $c;
                    });
                $echo = json_decode($body, true);
                $this->check(($echo['body'] ?? null) === '{"prompt":"hi"}', "$engine: body $body");
                $this->check(($echo['key'] ?? null) === 'k1', "$engine: header");
                $this->check(($echo['ae'] ?? null) === 'identity', "$engine: accept-encoding");
            });
        });
    }

    public function testTlsVerifiedWithCaAndThroughProxy(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $ca = $this->dir . '/ca.crt';
                $direct = new RestTemplate(new DefaultRestClientTransport(true, null, $ca), 5.0);
                $data = [];
                $direct->exchangeStream('GET', 'https://localhost:' . ($this->port + 1) . '/sse', null,
                    function (SseEvent $e) use (&$data): void {
                        $data[] = $e->data;
                    }, decoder: StreamDecoder::sse());
                $this->check(count($data) === 5, "$engine: tls direct got " . count($data));

                @unlink($this->dir . '/proxy.log');
                $proxied = new RestTemplate(new DefaultRestClientTransport(true, 'http://u:p%40ss@127.0.0.1:' . ($this->port + 4), $ca), 5.0);
                $data = [];
                $proxied->exchangeStream('GET', 'https://localhost:' . ($this->port + 1) . '/sse', null,
                    function (SseEvent $e) use (&$data): void {
                        $data[] = $e->data;
                    }, decoder: StreamDecoder::sse());
                $this->check(count($data) === 5, "$engine: tls via proxy got " . count($data));
                $body = '';
                $proxied->exchangeStream('POST', $this->url('/echo'), null, function (string $c) use (&$body): void {
                    $body .= $c;
                });
                $this->check(str_contains($body, '"ae":"identity"'), "$engine: http via proxy: $body");
                $log = (string)file_get_contents($this->dir . '/proxy.log');
                $this->check(str_contains($log, 'CONNECT localhost:' . ($this->port + 1) . ' auth=' . base64_encode('u:p@ss')), "$engine: proxy log $log");
                $this->check(str_contains($log, 'POST http://127.0.0.1:' . $this->port . '/echo auth=' . base64_encode('u:p@ss')), "$engine: proxy log $log");
            });
        });
    }

    public function testTlsVerificationFailsClosed(): void {
        $this->withServer(function (): void {
            $this->onBothEngines(function (string $engine): void {
                $ca = $this->dir . '/ca.crt';
                $cases = [
                    'self-signed, system CAs' => [null, $this->port + 2],
                    'self-signed, test CA' => [$ca, $this->port + 2],
                    'wrong host name' => [$ca, $this->port + 3],
                    'unknown CA (system CAs only)' => [null, $this->port + 1],
                ];
                foreach ($cases as $label => [$caFile, $port]) {
                    $called = false;
                    try {
                        (new RestTemplate(new DefaultRestClientTransport(true, null, $caFile), 3.0))
                            ->exchangeStream('GET', 'https://localhost:' . $port . '/sse', null, function () use (&$called): void {
                                $called = true;
                            });
                        $this->check(false, "$engine: $label was accepted");
                    } catch (RestClientException $e) {
                        $this->check(str_contains($e->getMessage(), 'TLS'), "$engine: $label: " . $e->getMessage());
                    }
                    $this->check(!$called, "$engine: $label delivered data");
                }
                // Verification explicitly off still connects (opt-in, as for exchange()).
                $n = 0;
                (new RestTemplate(new DefaultRestClientTransport(false), 3.0))
                    ->exchangeStream('GET', 'https://localhost:' . ($this->port + 2) . '/sse', null, function () use (&$n): void {
                        $n++;
                    });
                $this->check($n > 0, "$engine: verification off");
            });
        });
    }

    // ---- harness -------------------------------------------------------------

    private function url(string $path): string {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    private function sleep(float $s): void {
        Coroutine::getCid() > 0 ? Coroutine::sleep($s) : usleep((int)($s * 1_000_000));
    }

    /** Runs $fn outside any coroutine (PHP streams), then inside Co\run without hooks (Swoole socket). */
    private function onBothEngines(callable $fn): void {
        $this->check(HttpStreamClient::defaultSocket() instanceof PhpStreamSocket, 'stream engine outside coroutines');
        $fn('streams');
        Coroutine::set(['hook_flags' => 0]);
        $error = null;
        run(function () use ($fn, &$error): void {
            try {
                $this->check(HttpStreamClient::defaultSocket() instanceof CoroutineStreamSocket, 'coroutine engine');
                $fn('coroutine');
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }
    }

    private function withServer(callable $fn): void {
        $this->dir = sys_get_temp_dir() . '/wb-stream-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0700, true);
        $this->makeCerts();
        $this->port = 20000 + random_int(0, 9000);
        file_put_contents($this->dir . '/server.php', self::SERVER_SCRIPT);
        $this->server = proc_open(
            [PHP_BINARY, $this->dir . '/server.php', (string)$this->port, $this->dir],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.out', 'a'], 2 => ['file', $this->dir . '/server.out', 'a']],
            $pipes
        );
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($this->dir . '/ready')) {
                if (microtime(true) > $deadline) {
                    throw new \Exception('test server did not start: ' . @file_get_contents($this->dir . '/server.out'));
                }
                usleep(50_000);
            }
            $fn();
        } finally {
            proc_terminate($this->server, SIGKILL);
            proc_close($this->server);
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function makeCerts(): void {
        $d = escapeshellarg($this->dir);
        $cmd = "cd $d && openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 1 -subj '/CN=WB Test CA'"
            . " && openssl req -newkey rsa:2048 -nodes -keyout srv.key -out srv.csr -subj '/CN=localhost'"
            . " && printf 'subjectAltName=DNS:localhost\\n' > srv.ext"
            . " && openssl x509 -req -in srv.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out srv.crt -days 1 -extfile srv.ext"
            . " && openssl req -newkey rsa:2048 -nodes -keyout other.key -out other.csr -subj '/CN=other.example'"
            . " && printf 'subjectAltName=DNS:other.example\\n' > other.ext"
            . " && openssl x509 -req -in other.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out other.crt -days 1 -extfile other.ext"
            . " && openssl req -x509 -newkey rsa:2048 -nodes -keyout self.key -out self.crt -days 1 -subj '/CN=localhost'"
            . " -addext 'subjectAltName=DNS:localhost'";
        exec($cmd . ' 2>&1', $out, $code);
        $this->check($code === 0, 'openssl failed: ' . implode("\n", $out));
    }

    /**
     * Test servers on consecutive ports: P plain HTTP, P+1 TLS (test-CA
     * certificate for localhost), P+2 TLS self-signed, P+3 TLS (test-CA
     * certificate for other.example), P+4 an HTTP proxy (CONNECT and
     * absolute-form) that logs request lines.
     */
    private const SERVER_SCRIPT = <<<'PHP'
<?php
use Swoole\Coroutine as Co;
[$_, $port, $dir] = $argv;
$port = (int)$port;
Co::set(['hook_flags' => SWOOLE_HOOK_ALL, 'log_level' => SWOOLE_LOG_ERROR]);
Co\run(function () use ($port, $dir) {
    $routes = function (Co\Http\Server $s) use ($dir) {
        $s->handle('/sse', function ($req, $resp) {
            $resp->header('Content-Type', 'text/event-stream');
            for ($i = 0; $i < 5; $i++) { $resp->write("data: $i\n\n"); Co::sleep(0.2); }
            $resp->end();
        });
        $s->handle('/steady', function ($req, $resp) {
            for ($i = 0; $i < 10; $i++) { $resp->write("c$i"); Co::sleep(0.15); }
            $resp->end();
        });
        $s->handle('/gap', function ($req, $resp) {
            $resp->write('first'); Co::sleep(2.0); @$resp->write('second'); @$resp->end();
        });
        $s->handle('/endless', function ($req, $resp) use ($dir) {
            for ($i = 0; $i < 200; $i++) {
                if (!@$resp->write("tick $i\n")) { file_put_contents("$dir/abort.log", "client gone after $i\n"); return; }
                Co::sleep(0.05);
            }
            file_put_contents("$dir/abort.log", "wrote all\n");
            $resp->end();
        });
        $s->handle('/err', function ($req, $resp) {
            $resp->status(429); $resp->header('Content-Type', 'application/json'); $resp->end('{"error":"rate limited"}');
        });
        $s->handle('/echo', function ($req, $resp) {
            $resp->end(json_encode(['body' => $req->getContent(), 'key' => $req->header['x-api-key'] ?? null,
                'ae' => $req->header['accept-encoding'] ?? null]));
        });
    };
    $servers = [];
    $plain = new Co\Http\Server('127.0.0.1', $port);
    $routes($plain); $servers[] = $plain;
    foreach ([1 => 'srv', 2 => 'self', 3 => 'other'] as $off => $name) {
        foreach (['127.0.0.1', '::1'] as $addr) {
            $tls = new Co\Http\Server($addr, $port + $off, true);
            $tls->set(['ssl_cert_file' => "$dir/$name.crt", 'ssl_key_file' => "$dir/$name.key"]);
            $routes($tls); $servers[] = $tls;
        }
    }
    foreach ($servers as $s) { go(fn() => $s->start()); }

    $proxy = new Co\Server('127.0.0.1', $port + 4);
    $proxy->handle(function (Co\Server\Connection $conn) use ($dir) {
        $sock = $conn->exportSocket();
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $d = $sock->recv(65536, 5);
            if ($d === '' || $d === false) { return; }
            $head .= $d;
        }
        $first = strtok($head, "\r\n");
        [$verb, $target] = explode(' ', $first);
        $auth = preg_match('/Proxy-Authorization: Basic (\S+)/', $head, $m) ? $m[1] : '-';
        file_put_contents("$dir/proxy.log", "$verb $target auth=$auth\n", FILE_APPEND);
        $up = new Co\Socket(AF_INET, SOCK_STREAM, 0);
        if ($verb === 'CONNECT') {
            [, $upPort] = explode(':', $target);
            $up->connect('127.0.0.1', (int)$upPort);
            $sock->send("HTTP/1.1 200 Connection established\r\n\r\n");
            $rest = '';
        } else {
            $p = parse_url($target);
            $up->connect('127.0.0.1', (int)$p['port']);
            $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
            $rest = preg_replace('#^\S+ \S+#', "$verb $path", $head);
            $rest = preg_replace("#Proxy-Authorization: [^\r]*\r\n#", '', $rest);
        }
        if ($rest !== '') { $up->send($rest); }
        go(function () use ($sock, $up) {
            while (($x = $up->recv(65536, 10)) !== '' && $x !== false) { $sock->send($x); }
            $sock->close();
        });
        while (($x = $sock->recv(65536, 10)) !== '' && $x !== false) { $up->send($x); }
        $up->close();
    });
    go(fn() => $proxy->start());
    Co::sleep(0.2);
    file_put_contents("$dir/ready", '1');
});
PHP;
}
