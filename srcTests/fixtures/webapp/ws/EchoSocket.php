<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp\ws;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\web\WebSocketController;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\websocket\WebSocketErrorHandler;
use dev\winterframework\web\websocket\WebSocketHandler;
use dev\winterframework\web\websocket\WebSocketMessage;
use dev\winterframework\web\websocket\WebSocketSession;
use dev\winterframework\web\websocket\WebSocketSessions;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;
use winterBootTests\fixtures\webapp\FixtureContext;

/**
 * Behind FixtureAuthInterceptor (^/ws/secure): text commands drive the
 * scenarios of srcTests/swoole/WebSocketSwooleTest.
 */
#[WebSocketController(path: '/ws/secure/echo')]
class EchoSocket implements WebSocketHandler, WebSocketErrorHandler {

    /** @var array<string, array> Worker-local record of closes, keyed by user (one worker). */
    public static array $closed = [];

    #[Autowired]
    private WebSocketSessions $sessions;

    public function onOpen(WebSocketSession $session, HttpRequest $handshake): void {
        // Application data the tests find sessions by.
        $session->setAttribute('user', FixtureContext::user());
        if ($handshake->getQueryParam('reject') === '1') {
            $session->close(4003, 'rejected in onOpen');
            return;
        }
        if ($handshake->getQueryParam('slowopen') === '1') {
            // Messages sent meanwhile must wait for onOpen to finish.
            Coroutine::sleep(0.3);
        }
        $session->send([
            'type' => 'hello',
            'user' => FixtureContext::user(),
            'path' => $session->getPath(),
            'pid' => getmypid(),
            'id' => $session->getId(),
        ]);
    }

    public function onMessage(WebSocketSession $session, WebSocketMessage $message): void {
        if ($message->isBinary()) {
            $session->sendBinary(strrev($message->getPayload()));
            return;
        }
        $text = $message->getPayload();
        [$cmd, $arg] = array_pad(explode(':', $text, 2), 2, '');
        switch ($cmd) {
            case 'whoami':
                Coroutine::sleep((float)($arg ?: 0));
                $session->send(['user' => FixtureContext::user(), 'attr' => $session->getAttribute('user')]);
                return;
            case 'set':
                $session->setAttribute('note', $arg);
                $session->send('ok');
                return;
            case 'get':
                $session->send(['note' => $session->getAttribute('note')]);
                return;
            case 'slow':
                $token = $session->beginWork('answer');
                try {
                    for ($i = 0; $i < 50; $i++) {
                        if ($token->isCancelled()) {
                            $session->send('slow:cancelled:' . $token->getReason());
                            return;
                        }
                        $session->send('part:' . $i);
                        Coroutine::sleep(0.1);
                    }
                    $session->send('slow:done');
                } finally {
                    $session->endWork('answer', $token);
                }
                return;
            case 'stop':
                $session->send($session->cancelWork('answer', 'stopped by user') ? 'stopped' : 'nothing-running');
                return;
            case 'close':
                $session->close((int)$arg, 'bye');
                return;
            case 'peers':
                $session->send(['count' => count($this->sessions->findByAttribute('user', $arg))]);
                return;
            case 'notify':
                $sent = $this->sessions->sendToAttribute('user', $arg, ['type' => 'notification']);
                $session->send(['sent' => $sent]);
                return;
            case 'flood':
                // Two coroutines push large frames to the same connection at once.
                $n = (int)$arg;
                $done = new Coroutine\Channel(2);
                foreach (['A', 'B'] as $tag) {
                    Coroutine::create(function () use ($session, $tag, $n, $done): void {
                        for ($i = 0; $i < $n; $i++) {
                            $session->send($tag . '|' . $i . '|' . str_repeat($tag, 65536));
                        }
                        $done->push(true);
                    });
                }
                $done->pop(30);
                $done->pop(30);
                $session->send('flood:done');
                return;
            case 'fail':
                throw new RuntimeException('handler failure');
            default:
                $session->send('echo:' . $text);
        }
    }

    public function onClose(WebSocketSession $session, int $code, string $reason): void {
        self::$closed[(string)$session->getAttribute('user')] = [
            'code' => $code,
            'reason' => $reason,
            'contextUser' => FixtureContext::user(),
            'sendAfterClose' => $session->send('too late'),
            'isOpen' => $session->isOpen(),
        ];
    }

    public function onError(WebSocketSession $session, Throwable $e): void {
        $session->send(['error' => $e->getMessage()]);
    }
}
