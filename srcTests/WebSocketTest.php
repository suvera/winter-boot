<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\web\StreamedResponseWriter;
use dev\winterframework\core\web\websocket\WebSocketConfig;
use dev\winterframework\core\web\websocket\WebSocketEndpoint;
use dev\winterframework\core\web\websocket\WebSocketHandlerRegistry;
use dev\winterframework\core\web\websocket\WebSocketOriginPolicy;
use dev\winterframework\exception\DuplicatePathException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\web\client\sse\SseParser;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\SseEmitter;
use dev\winterframework\web\http\StreamingResponseEntity;
use dev\winterframework\web\websocket\WebSocketCloseCode;
use dev\winterframework\web\websocket\WebSocketMessage;
use dev\winterframework\web\websocket\WebSocketSession;
use dev\winterframework\web\websocket\WebSocketSessions;
use dev\winterframework\web\websocket\WebSocketTransport;
use winterBootTests\Support\TestCase;

final class RecordingWebSocketTransport implements WebSocketTransport {
    public array $frames = [];
    public array $closes = [];
    public bool $established = true;

    public function push(int $fd, string $data, bool $binary): bool {
        if (!$this->established) {
            return false;
        }
        $this->frames[] = [$fd, $data, $binary];
        return true;
    }

    public function disconnect(int $fd, int $code, string $reason): bool {
        $this->closes[] = [$fd, $code, $reason];
        $this->established = false;
        return true;
    }

    public function ping(int $fd): bool {
        $this->frames[] = [$fd, 'PING', false];
        return $this->established;
    }

    public function pong(int $fd, string $data): bool {
        return $this->established;
    }

    public function isEstablished(int $fd): bool {
        return $this->established;
    }
}

class WebSocketTest extends TestCase {
    private array $tmpDirs = [];

    public function __destruct() {
        foreach ($this->tmpDirs as $dir) {
            array_map(unlink(...), glob($dir . '/*'));
            @rmdir($dir);
        }
    }

    private function props(string $yml): WinterPropertyContext {
        $dir = sys_get_temp_dir() . '/wbws-' . uniqid();
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        file_put_contents($dir . '/application.yml', $yml);
        return new WinterPropertyContext([$dir]);
    }

    private function session(RecordingWebSocketTransport $t, int $id = 7): WebSocketSession {
        return new WebSocketSession($id, '/ws/chat', new HttpRequest(), $t, '/ws/chat');
    }

    // ---- Origin -----------------------------------------------------------

    public function testOriginSameHostByDefault(): void {
        $this->assertTrue(WebSocketOriginPolicy::isAllowed(null, 'app.example.com', []), 'no Origin: not a browser');
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('https://app.example.com', 'app.example.com', []));
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('https://App.Example.com:443', 'app.example.com:443', []));
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('http://127.0.0.1:8080', '127.0.0.1:8080', []));
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('http://127.0.0.1:9090', '127.0.0.1:8080', []), 'other port');
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('https://evil.io', 'app.example.com', []));
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('https://app.example.com.evil.io', 'app.example.com', []));
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('null', 'app.example.com', []), 'null never');
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('https://app.example.com', null, []), 'no Host');
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('file:///etc', 'app.example.com', []));
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('https://u:p@app.example.com', 'app.example.com', []));
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('http://[::1]:8080', '[::1]:8080', []));
    }

    public function testOriginAllowList(): void {
        $list = ['https://app.example.com', 'http://localhost:5173'];
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('https://app.example.com', 'api.internal', $list));
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('https://app.example.com:443', 'api.internal', $list));
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('http://localhost:5173', 'api.internal', $list));
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('http://localhost:5174', 'localhost:5174', $list), 'list replaces same-host');
        $this->assertFalse(WebSocketOriginPolicy::isAllowed('null', 'x', ['*']), 'null even with *');
        $this->assertTrue(WebSocketOriginPolicy::isAllowed('https://any.io', 'x', ['*']));
    }

    // ---- Config -----------------------------------------------------------

    public function testConfigDefaultsAndEnablement(): void {
        $p = $this->props("server:\n    port: 1\n");
        $c = WebSocketConfig::fromProperties($p);
        $this->assertSame([], $c->allowedOrigins);
        $this->assertSame(1048576, $c->maxFrameSize);
        $this->assertSame(90.0, $c->idleTimeout);
        $this->assertSame(30.0, $c->heartbeatInterval);
        $this->assertFalse(WebSocketConfig::isEnabled($p, false), 'no handler: plain HTTP');
        $this->assertTrue(WebSocketConfig::isEnabled($p, true));
        $this->assertTrue(WebSocketConfig::isEnabled($this->props("server:\n    websocket:\n        enabled: true\n"), false));
        $this->assertFalse(WebSocketConfig::isEnabled($this->props("server:\n    websocket:\n        enabled: false\n"), true));
    }

    public function testConfigParsesOriginsAndRejectsFrameSizeAbovePackageLimit(): void {
        $c = WebSocketConfig::fromProperties($this->props(
            "server:\n    websocket:\n        allowedOrigins: 'https://a.io, https://b.io'\n        maxFrameSize: 3000000\n        compression: true\n"
        ));
        $this->assertSame(['https://a.io', 'https://b.io'], $c->allowedOrigins);
        $this->assertThrows(WinterException::class, fn() => $c->swooleSettings([]));
        $settings = $c->swooleSettings(['package_max_length' => 4000000]);
        $this->assertTrue($settings['open_websocket_close_frame']);
        $this->assertTrue($settings['open_websocket_ping_frame']);
        $this->assertTrue($settings['websocket_compression']);
        $this->assertFalse(isset($settings['package_max_length']), 'HTTP body limit never touched');
        $this->assertSame(false, $c->swooleSettings(['package_max_length' => 4000000, 'websocket_compression' => false])['websocket_compression'] ?? false);
        $this->assertThrows(WinterException::class, fn() => WebSocketConfig::fromProperties(
            $this->props("server:\n    websocket:\n        maxFrameSize: 0\n")
        ));
    }

    // ---- Registry and handshake helpers -----------------------------------

    public function testRegistryNormalisesAndRejectsDuplicates(): void {
        $r = new WebSocketHandlerRegistry();
        $r->register('/ws//chat/', 'A');
        $this->assertSame('A', $r->find('ws/chat')['class']);
        $this->assertSame('/ws/chat', $r->find('/ws/chat/')['path']);
        $this->assertNull($r->find('ws/chat/x'));
        $this->assertThrows(DuplicatePathException::class, fn() => $r->register('ws/chat', 'B'));
        $this->assertThrows(WinterException::class, fn() => $r->register('/', 'C'));
        $this->assertThrows(WinterException::class, fn() => $r->register('/ws/{id}', 'C'));
    }

    public function testHandshakeKeyHelpers(): void {
        // RFC 6455 section 1.3 example.
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WebSocketEndpoint::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
        $this->assertTrue(WebSocketEndpoint::isValidKey('dGhlIHNhbXBsZSBub25jZQ=='));
        $this->assertFalse(WebSocketEndpoint::isValidKey(null));
        $this->assertFalse(WebSocketEndpoint::isValidKey('short'));
        $this->assertFalse(WebSocketEndpoint::isValidKey(base64_encode(str_repeat('x', 17))));
    }

    public function testCloseCodes(): void {
        $this->assertTrue(WebSocketCloseCode::isSendable(1000));
        $this->assertTrue(WebSocketCloseCode::isSendable(4999));
        $this->assertFalse(WebSocketCloseCode::isSendable(1005), 'reserved');
        $this->assertFalse(WebSocketCloseCode::isSendable(1006), 'reserved');
        $this->assertFalse(WebSocketCloseCode::isSendable(999));
        $this->assertFalse(WebSocketCloseCode::isSendable(5000));
        $this->assertSame('1001', WebSocketCloseCode::label(1001));
        $this->assertSame('app', WebSocketCloseCode::label(4123));
        $this->assertSame('other', WebSocketCloseCode::label(2999));
    }

    // ---- Session ----------------------------------------------------------

    public function testSessionSendAndClose(): void {
        $t = new RecordingWebSocketTransport();
        $s = $this->session($t);
        $this->assertTrue($s->send('hi'));
        $this->assertTrue($s->send(['a' => 'b/c']));
        $this->assertTrue($s->sendBinary("\x00"));
        $this->assertSame([[7, 'hi', false], [7, '{"a":"b/c"}', false], [7, "\x00", true]], $t->frames);
        $this->assertFalse($s->send(['bad' => NAN]), 'unencodable data: false, not an exception');

        $this->assertTrue($s->close(4001, str_repeat('é', 100)));
        $this->assertSame(4001, $t->closes[0][1]);
        $this->assertTrue(strlen($t->closes[0][2]) <= 123 && mb_check_encoding($t->closes[0][2], 'UTF-8'), 'reason cut on a character boundary');
        $this->assertFalse($s->close(), 'second close is a no-op');
        $this->assertFalse($s->isOpen());
        $this->assertFalse($s->send('late'), 'send after close is false');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->session(new RecordingWebSocketTransport())->close(1006));
    }

    public function testSessionClosesExactlyOnce(): void {
        $t = new RecordingWebSocketTransport();
        $s = $this->session($t);
        $s->recordPeerClose(4002, 'bye');
        $this->assertSame([4002, 'bye'], $s->markClosed());
        $this->assertNull($s->markClosed(), 'second finish ignored');

        $dropped = $this->session(new RecordingWebSocketTransport());
        $this->assertSame([WebSocketCloseCode::ABNORMAL, ''], $dropped->markClosed(), 'no close frame: 1006');
    }

    public function testSessionWorkCancellation(): void {
        $s = $this->session(new RecordingWebSocketTransport());
        $first = $s->beginWork('answer');
        $second = $s->beginWork('answer');
        $this->assertTrue($first->isCancelled(), 'replaced by a newer task');
        $this->assertSame('replaced', $first->getReason());
        $this->assertFalse($second->isCancelled());
        $this->assertTrue($s->cancelWork('answer', 'stop'));
        $this->assertFalse($s->cancelWork('answer'), 'already cancelled');
        $this->assertThrows(\dev\winterframework\web\websocket\CancelledException::class, fn() => $second->throwIfCancelled());
        $s->endWork('answer', $first);
        $this->assertSame($second, $s->getWork('answer'), 'endWork of an older token keeps the current one');
        $s->endWork('answer', $second);
        $this->assertNull($s->getWork('answer'));

        $running = $s->beginWork('other');
        $s->close();
        $this->assertTrue($running->isCancelled(), 'closing cancels running work');
        $this->assertTrue($s->beginWork('late')->isCancelled(), 'work begun after close starts cancelled');
    }

    public function testSessionsRegistry(): void {
        $t = new RecordingWebSocketTransport();
        $reg = new WebSocketSessions();
        $a = $this->session($t, 1);
        $a->setAttribute('userId', 5);
        $b = $this->session($t, 2);
        $b->setAttribute('userId', '5');
        $reg->add($a);
        $reg->add($b);
        $this->assertSame([$a], $reg->findByAttribute('userId', 5), 'strict comparison');
        $this->assertSame(1, $reg->sendToAttribute('userId', 5, ['n' => 1]));
        $this->assertSame(2, $reg->count());
        $this->assertSame($a, $reg->remove(1));
        $this->assertNull($reg->get(1));
        $this->assertSame([$b], $reg->all('/ws/chat'));
        $this->assertSame([], $reg->all('/other'));
    }

    public function testMessage(): void {
        $m = WebSocketMessage::text('{"q":"hi"}');
        $this->assertTrue($m->isText());
        $this->assertSame(['q' => 'hi'], $m->json());
        $this->assertTrue(WebSocketMessage::binary('x')->isBinary());
        $this->assertThrows(\JsonException::class, fn() => WebSocketMessage::text('nope')->json());
    }

    // ---- SSE round trip (server emitter -> client parser) -------------------

    public function testSseEmitterOutputParsesBack(): void {
        $entity = StreamingResponseEntity::sse(function (SseEmitter $sse): void {
            $sse->event('token', ['text' => "line1\nline2"], '1');
            $sse->data("plain\ntwo lines");
            $sse->comment('keep-alive');
            $sse->event('done', '[DONE]');
        });
        $stream = new RecordingChunkedStream();
        $writer = new StreamedResponseWriter($entity, $stream);
        $entity->getBody()->run($writer);
        $wire = implode('', $stream->chunks());

        // Feed it back one byte at a time, as a slow network would.
        $parser = new SseParser();
        $events = [];
        foreach (str_split($wire) as $byte) {
            foreach ($parser->feed($byte) as $event) {
                $events[] = $event;
            }
        }
        $this->assertSame(3, count($events));
        $this->assertSame('token', $events[0]->event);
        $this->assertSame(['text' => "line1\nline2"], $events[0]->json());
        $this->assertSame('1', $events[0]->id);
        $this->assertSame('message', $events[1]->event);
        $this->assertSame("plain\ntwo lines", $events[1]->data);
        $this->assertTrue($events[2]->isDone());
    }
}
