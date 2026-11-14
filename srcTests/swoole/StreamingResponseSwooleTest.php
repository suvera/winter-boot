<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use winterBootTests\swoole\support\FixtureServer;

/**
 * Streamed controller responses (StreamingResponseEntity / SseEmitter) end
 * to end: the fixture app runs in a subprocess and is read over raw sockets
 * so chunk arrival times are visible.
 */
class StreamingResponseSwooleTest {

    private function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \Exception($message);
        }
    }

    private function server(): FixtureServer {
        return (new FixtureServer())->start();
    }

    /** @return resource */
    private function openRequest(FixtureServer $s, string $path, array $headers = []) {
        $sock = stream_socket_client('tcp://127.0.0.1:' . $s->port, $errNo, $errStr, 5);
        $this->check($sock !== false, "connect: $errStr");
        $req = "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: user=alice\r\nConnection: close\r\n";
        foreach ($headers as $k => $v) {
            $req .= "$k: $v\r\n";
        }
        fwrite($sock, $req . "\r\n");
        return $sock;
    }

    /**
     * Reads until EOF, recording when each body line arrived.
     *
     * @return array{raw: string, lines: array<int, array{0: string, 1: float}>}
     */
    private function readTimed($sock, float $timeout = 10): array {
        stream_set_timeout($sock, (int)ceil($timeout));
        $raw = '';
        $lines = [];
        $buffer = '';
        $headerDone = false;
        while (!feof($sock)) {
            $data = fread($sock, 8192);
            if ($data === false || ($data === '' && stream_get_meta_data($sock)['timed_out'])) {
                break;
            }
            $now = microtime(true);
            $raw .= $data;
            $buffer .= $data;
            if (!$headerDone) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos === false) {
                    continue;
                }
                $headerDone = true;
                $buffer = substr($buffer, $pos + 4);
            }
            // Chunked framing: size line, data, CRLF.
            while (preg_match('/^([0-9a-fA-F]+)\r\n/', $buffer, $m)) {
                $len = hexdec($m[1]);
                if (strlen($buffer) < strlen($m[0]) + $len + 2) {
                    break;
                }
                $chunk = substr($buffer, strlen($m[0]), (int)$len);
                $buffer = substr($buffer, strlen($m[0]) + (int)$len + 2);
                if ($len > 0) {
                    $lines[] = [$chunk, $now];
                }
            }
        }
        fclose($sock);
        return ['raw' => $raw, 'lines' => $lines];
    }

    /** Reads until $needle arrived (headers and chunks may come in separate packets). */
    private function readUntil($sock, string $needle, float $timeout = 5): bool {
        stream_set_timeout($sock, 1);
        $seen = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline && !feof($sock)) {
            $seen .= (string)fread($sock, 4096);
            if (str_contains($seen, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function calls(FixtureServer $s, string $uri): array {
        $calls = json_decode($s->http('GET', '/calls')['body'], true);
        return $calls[$uri] ?? [];
    }

    public function testChunksArriveInOrderAsProduced(): void {
        $s = $this->server();
        $start = microtime(true);
        $res = $this->readTimed($this->openRequest($s, '/stream/chunks?n=5&delay=0.2'));
        $resp = FixtureServer::parseResponse($res['raw']);
        $this->check($resp['status'] === 200, 'status ' . $resp['status'] . "\n" . $s->log());
        $this->check(($resp['headers']['transfer-encoding'] ?? '') === 'chunked', 'chunked transfer');
        $this->check(!isset($resp['headers']['content-length']), 'no content-length');
        $this->check(($resp['headers']['x-fixture-auth'] ?? '') === 'ok', 'preHandle header sent with the head');
        $this->check(count($res['lines']) === 5, 'five chunks, got ' . count($res['lines']));
        foreach ($res['lines'] as $i => [$chunk, $at]) {
            $this->check(str_starts_with($chunk, "chunk-$i@"), "chunk $i in order: $chunk");
        }
        // Incremental: the first chunk arrived long before the last.
        $first = $res['lines'][0][1] - $start;
        $last = $res['lines'][4][1] - $start;
        $this->check($first < 0.5 && $last - $first > 0.6, sprintf('incremental delivery: first %.2fs last %.2fs', $first, $last));
        $s->stop();
    }

    public function testInterceptorsRunOnceAfterTheStream(): void {
        $s = $this->server();
        $res = $this->readTimed($this->openRequest($s, '/stream/user'));
        $resp = FixtureServer::parseResponse($res['raw']);
        // The interceptor's context is still bound while the producer runs.
        $this->check($resp['body'] === 'user=alice', 'context alive in the stream: ' . $resp['body']);
        usleep(100000);
        $calls = $this->calls($s, '/stream/user');
        $this->check($calls === ['pre' => 1, 'post' => 1, 'after' => 1], 'hooks once: ' . json_encode($calls));

        // A refused request streams nothing and never runs the producer.
        $refused = $s->http('GET', '/stream/user', ['Cookie' => 'user=nobody']);
        $this->check($refused['status'] === 401, 'refused: ' . $refused['status']);
        $s->stop();
    }

    public function testSseEventsAndHeartbeat(): void {
        $s = $this->server();
        $res = $this->readTimed($this->openRequest($s, '/stream/sse', ['Accept' => 'text/event-stream']));
        $resp = FixtureServer::parseResponse($res['raw']);
        $this->check(str_starts_with($resp['headers']['content-type'] ?? '', 'text/event-stream'), 'content type');
        $this->check(($resp['headers']['cache-control'] ?? '') === 'no-cache', 'no-cache');
        $body = $resp['body'];
        $this->check(str_starts_with($body, ": stream open\n\n"), 'opened with a comment: ' . substr($body, 0, 30));
        $this->check(str_contains($body, "event: token\nid: 1\ndata: {\"text\":\"Hel\"}\n\n"), 'first event');
        $this->check(str_contains($body, "event: token\nid: 2\ndata: {\"text\":\"lo\\nworld\"}\n\n"), 'json keeps newline escaped');
        $this->check(str_contains($body, "data: multi\ndata: line\n\n"), 'multi-line data');
        $this->check(substr_count($body, ": ping\n\n") >= 2, 'heartbeat pings during the 0.5s pause: ' . substr_count($body, ': ping'));
        $this->check(str_ends_with($body, "event: done\ndata: [DONE]\n\n"), 'done last, no ping after the end');
        // Every chunk is a whole event: a ping never splits one.
        foreach ($res['lines'] as [$chunk]) {
            $this->check(str_ends_with($chunk, "\n\n"), 'chunk is a complete event: ' . json_encode($chunk));
        }
        $s->stop();
    }

    public function testStreamIsNeverCompressed(): void {
        $s = $this->server();
        $res = $this->readTimed($this->openRequest($s, '/stream/chunks?n=3&delay=0.01', ['Accept-Encoding' => 'gzip, deflate']));
        $resp = FixtureServer::parseResponse($res['raw']);
        $this->check(!isset($resp['headers']['content-encoding']), 'no content-encoding on a stream');
        $this->check(str_starts_with($resp['body'], 'chunk-0@'), 'plain body');
        $s->stop();
    }

    public function testClientDisconnectStopsTheProducer(): void {
        $s = $this->server();
        $sock = $this->openRequest($s, '/stream/forever?id=w');
        $this->check($this->readUntil($sock, 'tick 0'), 'stream started');
        usleep(200000);
        fclose($sock);
        usleep(600000);
        $status = json_decode($s->http('GET', '/probe/stream-status?id=w')['body'], true);
        $this->check(($status['reason'] ?? '') === 'write-false', 'producer stopped on a failed write: ' . json_encode($status));
        $this->check($status['writes'] < 40, 'stopped promptly: ' . $status['writes']);
        $calls = $this->calls($s, '/stream/forever');
        $this->check($calls === ['pre' => 1, 'post' => 1, 'after' => 1], 'hooks once after disconnect: ' . json_encode($calls));
        $s->stop();
    }

    public function testIdleProducerSeesDisconnectWithoutWriting(): void {
        $s = $this->server();
        $sock = $this->openRequest($s, '/stream/idle?id=i');
        $this->check($this->readUntil($sock, 'started'), 'stream started');
        fclose($sock);
        usleep(500000);
        $status = json_decode($s->http('GET', '/probe/stream-status?id=i')['body'], true);
        $this->check(($status['reason'] ?? '') === 'disconnected', 'isConnected() turned false: ' . json_encode($status));
        $s->stop();
    }

    public function testProducerFailureBeforeFirstChunkIsAnErrorResponse(): void {
        $s = $this->server();
        $resp = $s->http('GET', '/stream/fail-early', ['Cookie' => 'user=alice']);
        $this->check($resp['status'] === 500, 'status 500: ' . $resp['status']);
        $this->check(str_contains($resp['body'], '"status":500'), 'error body: ' . $resp['body']);
        $this->check(!str_contains($resp['body'], 'producer failed'), 'no exception text leaked');
        $calls = $this->calls($s, '/stream/fail-early');
        $this->check($calls === ['pre' => 1, 'after' => 1], 'failed request: afterCompletion once, no postHandle: ' . json_encode($calls));
        $s->stop();
    }

    public function testProducerFailureMidStreamEndsTheResponse(): void {
        $s = $this->server();
        $res = $this->readTimed($this->openRequest($s, '/stream/fail-late'));
        $resp = FixtureServer::parseResponse($res['raw']);
        $this->check($resp['status'] === 200, 'status already sent: ' . $resp['status']);
        $this->check($resp['body'] === "partial\n", 'no error body appended: ' . json_encode($resp['body']));
        $this->check(str_ends_with($res['raw'], "0\r\n\r\n"), 'response ended with the last chunk');
        usleep(100000);
        $calls = $this->calls($s, '/stream/fail-late');
        $this->check($calls === ['pre' => 1, 'post' => 1, 'after' => 1], 'hooks once: ' . json_encode($calls));
        $s->stop();
    }
}
