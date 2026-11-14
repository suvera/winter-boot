<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;
use winterBootTests\fixtures\webapp\PlainFixtureApp;
use winterBootTests\swoole\support\FixtureServer;

/**
 * WebSocket support end to end: the fixture app (srcTests/fixtures/webapp,
 * handler ws/EchoSocket behind FixtureAuthInterceptor) runs in a
 * subprocess; clients are Swoole coroutine WebSocket clients.
 */
class WebSocketSwooleTest {
    /** @var array<int, int> worker pid per client (from the hello message) */
    private array $workerOf = [];

    /** Opens clients until they span at least two workers. */
    private function openOnTwoWorkers(FixtureServer $s, string $prefix, int $min = 4): array {
        $clients = [];
        for ($i = 0; $i < 40 && (count($clients) < $min || count(array_unique(array_intersect_key($this->workerOf, array_flip(array_map('spl_object_id', $clients))))) < 2); $i++) {
            $clients[] = $this->open($s, $prefix . $i);
        }
        $pids = array_unique(array_map(fn($c) => $this->workerOf[spl_object_id($c)], $clients));
        $this->check(count($pids) >= 2, 'clients on two workers: ' . json_encode($pids));
        return $clients;
    }

    private function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \Exception($message);
        }
    }

    /** Runs $fn in a coroutine; a failure inside is rethrown here (uncaught, it would kill the process). */
    private function run(callable $fn): void {
        $error = null;
        \Swoole\Coroutine\run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }
    }

    private function client(FixtureServer $s, ?string $user = 'alice', array $headers = []): Client {
        $c = new Client('127.0.0.1', $s->port);
        $c->set(['timeout' => 5, 'open_websocket_close_frame' => true]);
        if ($user !== null) {
            $c->setCookies(['user' => $user]);
        }
        if ($headers !== []) {
            $c->setHeaders($headers);
        }
        return $c;
    }

    /** Opens /ws/secure/echo and consumes the hello message. */
    private function open(FixtureServer $s, string $user = 'alice', string $query = ''): Client {
        $c = $this->client($s, $user);
        $this->check($this->upgrade($c, '/ws/secure/echo' . $query) === true, "upgrade for $user: status $c->statusCode\n" . $s->log());
        // Browsers verify the accept key; Swoole's client does not, so check it here.
        $key = $c->getHeaderOut() ? self::requestHeader((string)$c->getHeaderOut(), 'sec-websocket-key') : null;
        $this->check($key !== null && ($c->headers['sec-websocket-accept'] ?? '')
            === base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), 'RFC 6455 Sec-WebSocket-Accept');
        $hello = json_decode((string)$this->recv($c)->data, true);
        $this->check(($hello['type'] ?? '') === 'hello', 'hello first: ' . json_encode($hello));
        $this->workerOf[spl_object_id($c)] = $hello['pid'] ?? 0;
        return $c;
    }

    /** True only when the server switched protocols (upgrade() alone is true for any response). */
    private function upgrade(Client $c, string $path): bool {
        return $c->upgrade($path) && $c->statusCode === 101;
    }

    private static function requestHeader(string $head, string $name): ?string {
        foreach (explode("\r\n", $head) as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            if (strtolower(trim($k)) === $name) {
                return trim($v);
            }
        }
        return null;
    }

    private function recv(Client $c, float $timeout = 3): Frame {
        $frame = $c->recv($timeout);
        $this->check($frame instanceof Frame, 'expected a frame, got ' . var_export($frame, true) . " err=$c->errCode");
        return $frame;
    }

    private function json(Client $c): array {
        return (array)json_decode((string)$this->recv($c)->data, true);
    }

    private function closed(FixtureServer $s, string $user): array {
        for ($i = 0; $i < 20; $i++) {
            $res = json_decode($s->http('GET', '/probe/ws-closed?user=' . $user)['body'], true);
            if (($res['code'] ?? -1) !== -1) {
                return $res;
            }
            usleep(50000);
        }
        return ['code' => -1];
    }

    public function testHttpRoutesStillServedByTheWebSocketServer(): void {
        $s = (new FixtureServer())->start();
        $this->check($s->http('GET', '/hello')['body'] === 'hello', 'plain route');
        $who = $s->http('GET', '/secure/whoami', ['Cookie' => 'user=alice']);
        $this->check($who['body'] === '{"user":"alice"}', 'intercepted route: ' . $who['body']);
        $this->check(str_contains($s->log(), 'Starting Http+WebSocket server'), 'WebSocket server started');
        $s->stop();
    }

    public function testAppWithoutHandlerIsAPlainHttpServer(): void {
        $s = (new FixtureServer('', '', 1, PlainFixtureApp::class))->start();
        $this->check($s->http('GET', '/hello')['body'] === 'hello', 'plain route');
        $log = $s->log();
        $this->check(str_contains($log, 'Starting Http server on'), 'plain HTTP server: ' . $log);
        $this->check(!str_contains($log, 'WebSocket'), 'nothing WebSocket in the log');
        $this->run(function () use ($s): void {
            $c = $this->client($s);
            $this->check($this->upgrade($c, '/ws/secure/echo') === false, 'no upgrade on a plain HTTP server');
            $this->check($c->statusCode !== 101, 'not switched: ' . $c->statusCode);
        });
        $s->stop();
    }

    public function testHandshakeGoesThroughInterceptors(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s, null);
            $this->check($this->upgrade($c, '/ws/secure/echo') === false, 'refused without the cookie');
            $this->check($c->statusCode === 401, 'interceptor status: ' . $c->statusCode);
            $this->check($c->body === '{"error":"unauthorized"}', 'interceptor body: ' . $c->body);

            $c = $this->client($s);
            $this->check($this->upgrade($c, '/ws/secure/echo') === true, 'accepted with the cookie');
            $this->check(($c->headers['x-fixture-auth'] ?? '') === 'ok', 'interceptor headers go out with the 101');
            $hello = $this->json($c);
            $this->check($hello['user'] === 'alice', 'interceptor context during onOpen: ' . json_encode($hello));
            $c->close();
        });
        usleep(200000);
        $calls = json_decode($s->http('GET', '/calls')['body'], true)['/ws/secure/echo'] ?? [];
        $this->check($calls === ['pre' => 2, 'after' => 2, 'post' => 1], 'hooks per handshake: ' . json_encode($calls));
        $s->stop();
    }

    public function testUnknownPathAndBadOriginAreRefused(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s);
            $this->check($this->upgrade($c, '/ws/secure/nope') === false && $c->statusCode === 404, 'unknown path: ' . $c->statusCode);
            $this->check(!str_contains((string)$c->body, 'nope'), 'path not echoed');

            $c = $this->client($s, 'alice', ['Origin' => 'https://evil.example']);
            $this->check($this->upgrade($c, '/ws/secure/echo') === false && $c->statusCode === 403, 'foreign origin: ' . $c->statusCode);

            $c = $this->client($s, 'alice', ['Origin' => 'null']);
            $this->check($this->upgrade($c, '/ws/secure/echo') === false && $c->statusCode === 403, 'null origin: ' . $c->statusCode);

            $c = $this->client($s, 'alice', ['Origin' => 'http://127.0.0.1:' . $s->port]);
            $this->check($this->upgrade($c, '/ws/secure/echo') === true, 'same-host origin: ' . $c->statusCode);
            $c->close();
        });
        // The interceptor never ran for refused upgrades.
        $calls = json_decode($s->http('GET', '/calls')['body'], true)['/ws/secure/echo'] ?? [];
        $this->check(($calls['pre'] ?? 0) === 1, 'only the accepted handshake reached preHandle: ' . json_encode($calls));
        $s->stop();
    }

    public function testHandlerFailureBeforeUpgradeIsA500(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s);
            $this->check(!$this->upgrade($c, '/ws/broken'), 'not upgraded');
            $this->check($c->statusCode === 500, 'plain 500: ' . $c->statusCode);
            $this->check(!str_contains((string)$c->body, 'cannot build'), 'no exception text leaked');
            // The server is fine afterwards.
            $ok = $this->open($s, 'after');
            $ok->close();
        });
        $s->stop();
    }

    public function testMetricsCountedWithHandlerPathLabels(): void {
        $metricsYaml = "    prometheus:\n        beanClass: Prometheus\\Storage\\InMemory\n"
            . "management:\n    endpoints:\n        enabled: true\n    endpoint:\n        prometheus:\n            enabled: true";
        $s = (new FixtureServer('', $metricsYaml))->start();
        $before = $s->http('GET', '/prometheus')['body'];
        $this->check(!str_contains($before, 'websocket_'), 'no WebSocket metrics before the first connection');
        $this->run(function () use ($s): void {
            $c = $this->open($s, 'metric');
            $c->push('one');
            $this->recv($c);
            $c->push('close:4001');
            $this->recv($c);
        });
        usleep(200000);
        $out = $s->http('GET', '/prometheus')['body'];
        $this->check(str_contains($out, 'websocket_connections_opened{path="/ws/secure/echo"} 1'), "opened counter:\n" . $out);
        $this->check(str_contains($out, 'websocket_messages_received{path="/ws/secure/echo"} 2'), 'messages counter');
        $this->check(str_contains($out, 'websocket_connections_closed{path="/ws/secure/echo",code="app"} 1'), 'closed counter');
        $this->check(!str_contains($out, 'one'), 'no message content in metrics');
        $s->stop();
    }

    public function testRoutingUnderAContextPath(): void {
        $s = (new FixtureServer('', '', 1, '', '/api'))->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s);
            $this->check($this->upgrade($c, '/api/ws/secure/echo'), 'handler under the context path: ' . $c->statusCode);
            $hello = $this->json($c);
            $this->check($hello['path'] === '/api/ws/secure/echo', 'session path is the request path: ' . json_encode($hello));
            $c->push('ping');
            $this->check($this->recv($c)->data === 'echo:ping', 'messages routed to the handler');
            $c->close();

            // Same as HTTP routes (DispatcherServlet::stripContextPath): a path
            // outside the context path is matched as it is.
            $this->check($s->http('GET', '/hello')['body'] === 'hello', 'HTTP route without the prefix');
            $c = $this->client($s);
            $this->check($this->upgrade($c, '/ws/secure/echo'), 'WebSocket path without the prefix, like HTTP');
            $c->close();
            $c = $this->client($s);
            $this->check(!$this->upgrade($c, '/api/ws/nope') && $c->statusCode === 404, 'unknown path under the prefix: ' . $c->statusCode);
        });
        $s->stop();
    }

    public function testAllowedOriginsConfig(): void {
        $s = (new FixtureServer("    websocket:\n        allowedOrigins: [ 'https://app.example.com' ]"))->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s, 'alice', ['Origin' => 'https://app.example.com']);
            $this->check($this->upgrade($c, '/ws/secure/echo') === true, 'listed origin');
            $c->close();
            $c = $this->client($s, 'alice', ['Origin' => 'http://127.0.0.1:' . $s->port]);
            $this->check($this->upgrade($c, '/ws/secure/echo') === false && $c->statusCode === 403, 'same host is not listed');
        });
        $s->stop();
    }

    public function testEchoTextAndBinary(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s);
            $c->push('hi there');
            $this->check($this->recv($c)->data === 'echo:hi there', 'text echo');
            $c->push("\x00\x01\x02\xff", WEBSOCKET_OPCODE_BINARY);
            $frame = $this->recv($c);
            $this->check($frame->opcode === WEBSOCKET_OPCODE_BINARY && $frame->data === "\xff\x02\x01\x00", 'binary echo');
            $c->push('fail');
            $this->check($this->json($c) === ['error' => 'handler failure'], 'onError receives handler exceptions');
            $c->push('still open');
            $this->check($this->recv($c)->data === 'echo:still open', 'connection survives a handler error');
            $c->close();
        });
        $s->stop();
    }

    public function testContextAndAttributesRestoredOnEveryMessage(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s, 'alice');
            for ($i = 0; $i < 3; $i++) {
                $c->push('whoami');
                $this->check($this->json($c) === ['user' => 'alice', 'attr' => 'alice'], "message $i sees the handshake context");
            }
            $c->push('set:remember-me');
            $this->check($this->recv($c)->data === 'ok', 'set');
            $c->push('get');
            $this->check($this->json($c) === ['note' => 'remember-me'], 'attribute kept across messages');
            $c->close();
        });
        $s->stop();
    }

    public function testConnectionsNeverSeeEachOthersContext(): void {
        $s = (new FixtureServer())->start();
        $results = [];
        $this->run(function () use ($s, &$results): void {
            $alice = $this->open($s, 'alice');
            $bob = $this->open($s, 'bob');
            // Interleaved, with delays so both handlers are suspended at once.
            for ($i = 0; $i < 5; $i++) {
                $alice->push('whoami:0.05');
                $bob->push('whoami:0.02');
            }
            for ($i = 0; $i < 5; $i++) {
                $results[] = ['alice', $this->json($alice)];
                $results[] = ['bob', $this->json($bob)];
            }
            $alice->push('peers:bob');
            $this->check($this->json($alice) === ['count' => 1], 'WebSocketSessions finds bob by attribute');
            $alice->push('notify:bob');
            $this->check($this->json($alice) === ['sent' => 1], 'pushed to bob');
            $this->check($this->json($bob) === ['type' => 'notification'], 'bob got the push');
            $alice->close();
            $bob->close();
        });
        foreach ($results as [$who, $res]) {
            $this->check($res === ['user' => $who, 'attr' => $who], "$who saw " . json_encode($res));
        }
        $s->stop();
    }

    public function testLongRunningMessageCancelledByASecondMessage(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s);
            $c->push('slow');
            $this->check($this->recv($c)->data === 'part:0', 'first part');
            $this->check($this->recv($c)->data === 'part:1', 'second part');
            $c->push('stop');
            $seen = [];
            for ($i = 0; $i < 6; $i++) {
                $data = $this->recv($c)->data;
                $seen[] = $data;
                if (str_starts_with($data, 'slow:')) {
                    break;
                }
            }
            $this->check(in_array('stopped', $seen, true), 'stop handled while slow runs: ' . json_encode($seen));
            $this->check(end($seen) === 'slow:cancelled:stopped by user', 'slow saw the cancel: ' . json_encode($seen));
            $c->close();
        });
        $s->stop();
    }

    public function testConcurrentSendsFromTwoCoroutinesStayIntact(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s, 'flo');
            $c->push('flood:100');
            $next = ['A' => 0, 'B' => 0];
            for ($k = 0; $k < 200; $k++) {
                [$tag, $i, $payload] = explode('|', (string)$this->recv($c, 10)->data, 3);
                $this->check($i === (string)$next[$tag], "$tag frames in order: got $i, expected {$next[$tag]}");
                $this->check($payload === str_repeat($tag, 65536), "$tag frame $i intact");
                $next[$tag]++;
            }
            $this->check($this->recv($c)->data === 'flood:done', 'all 200 frames, nothing extra');
            $c->close();
        });
        $s->stop();
    }

    public function testMessagesWaitForOnOpen(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s);
            $this->check($this->upgrade($c, '/ws/secure/echo?slowopen=1') === true, 'upgrade');
            $c->push('first');
            $this->check($this->json($c)['type'] === 'hello', 'onOpen finished before the message ran');
            $this->check($this->recv($c)->data === 'echo:first', 'then the message');
            $c->close();
        });
        $s->stop();
    }

    public function testCloseCodes(): void {
        $s = (new FixtureServer())->start();
        $this->run(function () use ($s): void {
            // Server-initiated close with an application code.
            $c = $this->open($s, 'carol');
            $c->push('close:4001');
            $frame = $this->recv($c);
            $this->check($frame instanceof CloseFrame && $frame->code === 4001 && $frame->reason === 'bye', 'server close frame');

            // Client-initiated close: onClose gets the client's code.
            $c = $this->open($s, 'dave');
            $close = new CloseFrame();
            $close->code = 4002;
            $close->reason = 'client leaving';
            $c->push($close);
            Coroutine::sleep(0.2);

            // onOpen rejects.
            $c = $this->client($s, 'erin');
            $this->check($this->upgrade($c, '/ws/secure/echo?reject=1') === true, 'upgrade before onOpen rejects');
            $frame = $this->recv($c);
            $this->check($frame instanceof CloseFrame && $frame->code === 4003, 'rejected in onOpen');

            // Dropped without a close frame.
            $c = $this->open($s, 'frank');
            $c->close();
        });
        $carol = $this->closed($s, 'carol');
        $this->check($carol['code'] === 4001 && $carol['reason'] === 'bye', 'carol onClose: ' . json_encode($carol));
        $this->check($carol['contextUser'] === 'carol', 'context restored in onClose');
        $this->check($carol['sendAfterClose'] === false && $carol['isOpen'] === false, 'send to a closed session is false');
        $dave = $this->closed($s, 'dave');
        $this->check($dave['code'] === 4002 && $dave['reason'] === 'client leaving', 'dave onClose: ' . json_encode($dave));
        $erin = $this->closed($s, 'erin');
        $this->check($erin['code'] === 4003, 'erin onClose once after onOpen: ' . json_encode($erin));
        $frank = $this->closed($s, 'frank');
        $this->check($frank['code'] === 1006 || $frank['code'] === 1005, 'frank dropped: ' . json_encode($frank));
        $s->stop();
    }

    public function testIdleTimeoutClosesSilentConnections(): void {
        $s = (new FixtureServer("    websocket:\n        idleTimeout: 1\n        heartbeatInterval: 0"))->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s, 'ivan');
            $start = microtime(true);
            $frame = $c->recv(4);
            $elapsed = microtime(true) - $start;
            $this->check($frame instanceof CloseFrame && $frame->code === 1000 && $frame->reason === 'Idle timeout',
                'idle close: ' . var_export($frame, true));
            $this->check($elapsed > 0.8 && $elapsed < 2.5, 'after about a second: ' . round($elapsed, 2));
        });
        $s->stop();
    }

    public function testHeartbeatPingsAndPongsKeepTheConnection(): void {
        $s = (new FixtureServer("    websocket:\n        idleTimeout: 1\n        heartbeatInterval: 0.3"))->start();
        $this->run(function () use ($s): void {
            $c = $this->client($s, 'judy');
            $c->set(['open_websocket_ping_frame' => true]);
            $this->check($this->upgrade($c, '/ws/secure/echo'), 'upgrade');
            $this->recv($c);
            $pings = 0;
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $frame = $c->recv(0.5);
                if ($frame instanceof Frame && $frame->opcode === WEBSOCKET_OPCODE_PING) {
                    $pings++;
                    $pong = new Frame();
                    $pong->opcode = WEBSOCKET_OPCODE_PONG;
                    $pong->data = $frame->data;
                    $c->push($pong);
                }
                $this->check(!$frame instanceof CloseFrame, 'answered pings keep it open');
            }
            $this->check($pings >= 3, 'server pinged: ' . $pings);
            $c->push('still here');
            $this->check($this->recv($c)->data === 'echo:still here', 'open after 2s with a 1s idle timeout');
            $c->close();
        });
        $s->stop();
    }

    public function testOversizedMessageClosesWith1009(): void {
        $s = (new FixtureServer("    websocket:\n        maxMessageSize: 1024"))->start();
        $this->run(function () use ($s): void {
            $c = $this->open($s, 'kate');
            $c->push(str_repeat('a', 1024));
            $this->check($this->recv($c)->data === 'echo:' . str_repeat('a', 1024), 'at the limit');
            $c->push(str_repeat('a', 1025));
            $frame = $this->recv($c);
            $this->check($frame instanceof CloseFrame && $frame->code === 1009, 'too big: ' . var_export($frame, true));
        });
        $s->stop();
    }

    public function testShutdownWithOpenConnectionsIsClean(): void {
        $s = (new FixtureServer('', '', 2))->start();
        $codes = [];
        $this->run(function () use ($s, &$codes): void {
            $clients = $this->openOnTwoWorkers($s, 'sd');
            $s->signal(SIGTERM);
            foreach ($clients as $c) {
                $frame = $c->recv(5);
                $codes[] = $frame instanceof CloseFrame ? $frame->code : ($frame === '' || $frame === false ? 'drop' : 'other');
            }
        });
        $deadline = microtime(true) + 8;
        while ($s->isRunning() && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->check(!$s->isRunning(), 'server exited');
        $log = $s->log();
        $this->check(!preg_match('/Fatal|Uncaught|must be called in the coroutine/i', $log), "no fatal on shutdown:\n" . $log);
        foreach ($codes as $code) {
            $this->check($code === 1001, 'every client got 1001: ' . json_encode($codes));
        }
        $this->check(substr_count($log, 'WebSocket closed /ws/secure/echo') === count($codes), "onClose ran for every connection:\n" . $log);
        $s->stop();
    }

    public function testSingleWorkerShutdownCloses1001(): void {
        $s = (new FixtureServer())->start();
        $codes = [];
        $this->run(function () use ($s, &$codes): void {
            $clients = [$this->open($s, 'one'), $this->open($s, 'two')];
            $s->signal(SIGTERM);
            foreach ($clients as $c) {
                $frame = $c->recv(5);
                $codes[] = $frame instanceof CloseFrame ? $frame->code : 'none';
            }
        });
        $this->check($codes === [1001, 1001], 'single worker: ' . json_encode($codes));
        $s->stop();
    }

    public function testAdminPortStaysPlainHttp(): void {
        $adminPort = FixtureServer::freePort();
        $tokenFile = sys_get_temp_dir() . '/wb-admin-token-' . getmypid();
        file_put_contents($tokenFile, 'secret-token');
        $s = (new FixtureServer(
            "    admin:\n        port: $adminPort",
            "    admin:\n        auth:\n            tokenFile: $tokenFile"
        ))->start();
        try {
            // The admin handler answers (wrong token: 400 NOK), not the app.
            $sock = stream_socket_client('tcp://127.0.0.1:' . $adminPort, $errNo, $errStr, 5);
            $this->check($sock !== false, "admin port listening: $errStr");
            fwrite($sock, "POST / HTTP/1.1\r\nHost: x\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 11\r\nConnection: close\r\n\r\ntoken=wrong");
            stream_set_timeout($sock, 5);
            $resp = FixtureServer::parseResponse((string)stream_get_contents($sock));
            fclose($sock);
            $this->check($resp['status'] === 400 && $resp['body'] === "NOK\n", 'admin handler served it: ' . json_encode($resp));

            $this->run(function () use ($adminPort): void {
                $c = new Client('127.0.0.1', $adminPort);
                $c->set(['timeout' => 3]);
                $c->setCookies(['user' => 'alice']);
                $this->check(!($c->upgrade('/ws/secure/echo') && $c->statusCode === 101), 'no WebSocket on the admin port: ' . $c->statusCode);
            });
            $this->check($s->http('GET', '/hello')['body'] === 'hello', 'app port unaffected');
        } finally {
            @unlink($tokenFile);
            $s->stop();
        }
    }

    public function testReloadClosesWith1001(): void {
        $s = (new FixtureServer('', '', 2))->start();
        $this->run(function () use ($s): void {
            $clients = $this->openOnTwoWorkers($s, 'rl');
            $s->signal(SIGUSR1);
            foreach ($clients as $i => $c) {
                $frame = $c->recv(4);
                $this->check($frame instanceof CloseFrame && $frame->code === 1001, "client $i got 1001: " . var_export($frame, true));
            }
            Coroutine::sleep(0.5);
            $c = $this->open($s, 'otto');
            $c->push('back');
            $this->check($this->recv($c)->data === 'echo:back', 'reconnect after reload');
            $c->close();
        });
        $s->stop();
    }
}
