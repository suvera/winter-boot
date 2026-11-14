<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\format\AbstractResponseRenderer;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\http\SwooleRequest;
use dev\winterframework\web\http\SwooleResponseEntity;
use dev\winterframework\web\websocket\WebSocketCloseCode;
use dev\winterframework\web\websocket\WebSocketErrorHandler;
use dev\winterframework\web\websocket\WebSocketHandler;
use dev\winterframework\web\websocket\WebSocketMessage;
use dev\winterframework\web\websocket\WebSocketSession;
use dev\winterframework\web\websocket\WebSocketSessions;
use dev\winterframework\web\websocket\WebSocketTransport;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Timer;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;
use Throwable;

/**
 * Serves WebSocket connections for one worker: the Swoole handshake,
 * message and close events, the idle/heartbeat sweep, and closing every
 * connection with 1001 when the worker stops or reloads.
 *
 * Logging and metrics never contain message bodies, headers or query
 * values; labels use the handler's registered path.
 */
class WebSocketEndpoint {
    use Wlf4p;

    /** How long a message waits for onOpen() to return. */
    private const OPEN_WAIT_SECONDS = 30.0;
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** How often a worker checks whether it is exiting (ms). */
    private const EXIT_WATCH_MS = 100;

    private ?int $timerId = null;
    private bool $exiting = false;
    private bool $metricsRegistered = false;
    private float $lastSweep = 0.0;
    /** @var array<int, float> last ping per session id; bounded by the open sessions */
    private array $lastPing = [];

    public function __construct(
        private ApplicationContext $appCtx,
        private DispatcherServlet $dispatcher,
        private InterceptorRegistry $interceptors,
        private WebSocketHandlerRegistry $handlers,
        private WebSocketSessions $sessions,
        private WebSocketConfig $config,
        private WebSocketTransport $transport,
        private ?PrometheusMetricRegistry $metrics = null
    ) {
    }

    public function getSessions(): WebSocketSessions {
        return $this->sessions;
    }

    // ---- Handshake -------------------------------------------------------

    /** Swoole 'handshake' event. */
    public function onHandshake(Request $request, Response $response): void {
        $httpRequest = new SwooleRequest($request, $response);
        $entity = new SwooleResponseEntity($response);
        $entity->setOutputStream(new HandshakeOutputStream($response));
        $fd = (int)$request->fd;
        $this->dispatcher->dispatchHandshake(
            $httpRequest,
            $entity,
            fn(string $uri): ?HttpRestException => $this->precheck($uri, $httpRequest),
            function (string $uri) use ($httpRequest, $entity, $response, $fd): void {
                $this->accept($uri, $fd, $httpRequest, $entity, $response);
            }
        );
    }

    /**
     * Validates an upgrade before any interceptor runs: a known handler
     * path (404), a usable WebSocket key and version (400/426) and an
     * allowed Origin (403). Messages are fixed text, never request data.
     */
    public function precheck(string $uri, HttpRequest $request): ?HttpRestException {
        if ($this->handlers->find($uri) === null) {
            return new HttpRestException(HttpStatus::$NOT_FOUND, 'No WebSocket endpoint at this path');
        }
        if (strtoupper($request->getMethod()) !== 'GET' || !self::isValidKey($request->getFirstHeader('Sec-WebSocket-Key'))) {
            return new HttpRestException(HttpStatus::$BAD_REQUEST, 'Invalid WebSocket handshake');
        }
        if (trim((string)$request->getFirstHeader('Sec-WebSocket-Version')) !== '13') {
            return new HttpRestException(HttpStatus::$UPGRADE_REQUIRED, 'Unsupported WebSocket version');
        }
        if (!WebSocketOriginPolicy::isAllowed(
            $request->getFirstHeader('Origin'),
            $request->getFirstHeader('Host'),
            $this->config->allowedOrigins
        )) {
            return new HttpRestException(HttpStatus::$FORBIDDEN, 'Origin not allowed');
        }
        return null;
    }

    public static function isValidKey(?string $key): bool {
        if ($key === null) {
            return false;
        }
        $raw = base64_decode(trim($key), true);
        return $raw !== false && strlen($raw) === 16;
    }

    public static function acceptKey(string $key): string {
        return base64_encode(sha1(trim($key) . self::GUID, true));
    }

    /**
     * Sends the 101 response (with any headers and cookies the
     * interceptors added), registers the session before the client can
     * send, runs the context hooks' capture() and onOpen() in this
     * coroutine, then releases messages that arrived meanwhile.
     */
    private function accept(string $uri, int $fd, HttpRequest $request, ResponseEntity $entity, Response $response): void {
        $entry = $this->handlers->find($uri);
        if ($entry === null) {
            throw new HttpRestException(HttpStatus::$NOT_FOUND, 'No WebSocket endpoint at this path');
        }
        $handler = WebSocketHandlerRegistry::resolve($this->appCtx, $entry);

        $headers = $entity->getHeaders();
        $headers->remove('Content-Type');
        $headers->remove('Content-Length');
        $headers->set('Upgrade', 'websocket');
        $headers->set('Connection', 'Upgrade');
        $headers->set('Sec-WebSocket-Accept', self::acceptKey((string)$request->getFirstHeader('Sec-WebSocket-Key')));
        $headers->set('Sec-WebSocket-Version', '13');

        // Registered before the 101 goes out, so a frame the client sends
        // right after it already finds its session (and waits for onOpen).
        $session = new WebSocketSession(
            $fd,
            '/' . ltrim((string)strtok($request->getUri(), '?'), '/'),
            $request,
            $this->transport,
            $entry['path']
        );
        $this->sessions->add($session);
        $this->lastPing[$fd] = microtime(true);
        try {
            $entity->withStatus(HttpStatus::$SWITCHING_PROTOCOLS);
            AbstractResponseRenderer::writeHead($entity, $entity->getOutputStream());
            $response->end();
        } catch (Throwable $e) {
            // Not upgraded: forget the session; the dispatcher answers 500.
            $this->sessions->remove($fd);
            unset($this->lastPing[$fd]);
            $session->markClosed();
            $session->markOpenCompleted();
            $entity->withStatus(HttpStatus::$INTERNAL_SERVER_ERROR);
            foreach (['Upgrade', 'Connection', 'Sec-WebSocket-Accept', 'Sec-WebSocket-Version'] as $name) {
                $headers->remove($name);
            }
            throw $e;
        }

        $this->count('websocket_connections_opened', ['path' => $entry['path']]);
        self::logInfo('WebSocket opened ' . $entry['path'] . ' #' . $fd);

        try {
            foreach ($this->interceptors->getWebSocketContextHooks() as $hook) {
                $hook->capture($session, $request);
            }
            $handler->onOpen($session, $request);
        } catch (Throwable $e) {
            $this->reportError($handler, $session, $e);
            if ($session->isOpen()) {
                $session->close(WebSocketCloseCode::INTERNAL_ERROR, 'Internal error');
            }
        } finally {
            $session->markOpenCompleted();
        }
    }

    // ---- Frames ----------------------------------------------------------

    /** Swoole 'message' event: one coroutine per frame. */
    public function onMessage(mixed $server, Frame $frame): void {
        $this->handleFrame(
            (int)$frame->fd,
            (int)$frame->opcode,
            (string)$frame->data,
            $frame instanceof CloseFrame ? (int)$frame->code : 0,
            $frame instanceof CloseFrame ? (string)$frame->reason : ''
        );
    }

    public function handleFrame(int $fd, int $opcode, string $data, int $closeCode = 0, string $closeReason = ''): void {
        $session = $this->sessions->get($fd);
        if ($session === null) {
            return;
        }
        $session->touch();

        switch ($opcode) {
            case WEBSOCKET_OPCODE_PING:
                $this->transport->pong($fd, $data);
                return;
            case WEBSOCKET_OPCODE_PONG:
                return;
            case WEBSOCKET_OPCODE_CLOSE:
                // Echo the peer's code (RFC 6455 §5.5.1); the close event finishes.
                $code = $closeCode > 0 ? $closeCode : WebSocketCloseCode::NO_STATUS;
                $session->recordPeerClose($code, $closeReason);
                $this->transport->disconnect(
                    $fd,
                    WebSocketCloseCode::isSendable($code) ? $code : WebSocketCloseCode::NORMAL,
                    ''
                );
                return;
            case WEBSOCKET_OPCODE_TEXT:
            case WEBSOCKET_OPCODE_BINARY:
                $this->dispatchMessage($session, $data, $opcode === WEBSOCKET_OPCODE_BINARY);
                return;
            default:
                return;
        }
    }

    private function dispatchMessage(WebSocketSession $session, string $data, bool $binary): void {
        $path = $session->getHandlerPath();
        if (strlen($data) > $this->config->maxFrameSize) {
            $this->count('websocket_errors', ['path' => $path]);
            self::logInfo('WebSocket ' . $path . ' #' . $session->getId() . ' message too big (' . strlen($data) . ' bytes)');
            $session->close(WebSocketCloseCode::MESSAGE_TOO_BIG, 'Message too big');
            return;
        }
        if (!$session->awaitOpen(self::OPEN_WAIT_SECONDS) || !$session->isOpen()) {
            return;
        }
        if ($session->enterMessage() > $this->config->maxConcurrentMessages) {
            $session->leaveMessage();
            $this->count('websocket_errors', ['path' => $path]);
            self::logInfo('WebSocket ' . $path . ' #' . $session->getId() . ' exceeded maxConcurrentMessages');
            $session->close(WebSocketCloseCode::POLICY_VIOLATION, 'Too many concurrent messages');
            return;
        }
        $session->countMessage();
        $this->count('websocket_messages_received', ['path' => $path]);
        try {
            $handler = $this->handlerOf($session);
            $message = new WebSocketMessage($data, $binary);
            $this->invoke($handler, $session, fn() => $handler->onMessage($session, $message));
        } finally {
            $session->leaveMessage();
        }
    }

    // ---- Close -----------------------------------------------------------

    /** Swoole 'close' event: fires for every connection, HTTP ones included. */
    public function onClose(mixed $server, int $fd): void {
        $session = $this->sessions->remove($fd);
        if ($session !== null) {
            $this->finish($session);
        }
    }

    /**
     * Runs onClose() exactly once (whichever of the close event, worker
     * stop or a failed open gets here first), after onOpen() returned.
     */
    public function finish(WebSocketSession $session): void {
        $this->sessions->remove($session->getId());
        unset($this->lastPing[$session->getId()]);
        $closed = $session->markClosed();
        if ($closed === null) {
            return;
        }
        [$code, $reason] = $closed;
        $session->awaitOpen(self::OPEN_WAIT_SECONDS);

        $path = $session->getHandlerPath();
        $this->count('websocket_connections_closed', ['path' => $path, 'code' => WebSocketCloseCode::label($code)]);
        self::logInfo(sprintf(
            'WebSocket closed %s #%d code=%d messages=%d after %.1fs',
            $path,
            $session->getId(),
            $code,
            $session->getMessagesReceived(),
            microtime(true) - $session->getOpenedAt()
        ));
        try {
            $handler = $this->handlerOf($session);
        } catch (Throwable $e) {
            self::logException($e);
            return;
        }
        $this->invoke($handler, $session, fn() => $handler->onClose($session, $code, $reason));
    }

    /**
     * Closes every connection of this worker (1001 by default: clients
     * should reconnect). Used on reload/shutdown.
     */
    public function closeAll(int $code = WebSocketCloseCode::GOING_AWAY, string $reason = 'Server restarting'): int {
        $n = 0;
        foreach ($this->sessions->all() as $session) {
            if ($session->close($code, $reason)) {
                $n++;
            }
        }
        if ($n > 0) {
            self::logInfo("Closed $n WebSocket connection(s) with $code");
        }
        return $n;
    }

    /**
     * Worker stop. Swoole calls it outside any coroutine, after the event
     * loop ended, so the connections are already gone: onClose() still runs
     * once for every session left, inside a coroutine of its own so
     * handlers and context hooks get their usual environment.
     */
    public function onWorkerStop(): void {
        $this->stopTimer();
        $left = $this->sessions->all();
        if ($left === []) {
            return;
        }
        $finishAll = function () use ($left): void {
            foreach ($left as $session) {
                $session->recordPeerClose(WebSocketCloseCode::GOING_AWAY, 'Server restarting');
                $this->finish($session);
            }
        };
        if (class_exists(Coroutine::class) && Coroutine::getCid() <= 0) {
            \Swoole\Coroutine\run($finishAll);
        } else {
            $finishAll();
        }
    }

    // ---- Timers: idle timeout, heartbeat, worker exit ---------------------

    /**
     * Starts this worker's timers (HTTP workers only): the idle/heartbeat
     * sweep, and a watch that closes every connection with 1001 as soon as
     * the worker starts exiting (shutdown or reload) while its event loop
     * still runs, so clients get a close frame and reconnect.
     *
     * @param callable(): bool|null $isExiting e.g. Server::getWorkerStatus() === SWOOLE_WORKER_EXIT
     */
    public function startTimer(?callable $isExiting = null): void {
        if ($this->timerId !== null || !class_exists(Timer::class)) {
            return;
        }
        $interval = $this->sweepInterval();
        $this->timerId = Timer::tick(self::EXIT_WATCH_MS, function () use ($interval, $isExiting): void {
            if (!$this->exiting && $isExiting !== null && $isExiting()) {
                $this->exiting = true;
                $this->closeAll();
                return;
            }
            $now = microtime(true);
            if ($interval > 0 && $now - $this->lastSweep >= $interval) {
                $this->lastSweep = $now;
                $this->sweep($now);
            }
        });
    }

    public function stopTimer(): void {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
    }

    /** Seconds between sweeps, or 0 when neither idle timeout nor heartbeat is on. */
    public function sweepInterval(): float {
        $candidates = [];
        if ($this->config->heartbeatInterval > 0) {
            $candidates[] = $this->config->heartbeatInterval;
        }
        if ($this->config->idleTimeout > 0) {
            $candidates[] = $this->config->idleTimeout / 2;
        }
        if ($candidates === []) {
            return 0.0;
        }
        return max(0.05, min($candidates));
    }

    /** Closes idle connections and pings the others when due. */
    public function sweep(float $now): void {
        foreach ($this->sessions->all() as $session) {
            if (!$session->isOpen()) {
                continue;
            }
            $id = $session->getId();
            if ($this->config->idleTimeout > 0 && $now - $session->getLastSeen() >= $this->config->idleTimeout) {
                self::logInfo('WebSocket ' . $session->getHandlerPath() . ' #' . $id . ' idle timeout');
                $session->close(WebSocketCloseCode::NORMAL, 'Idle timeout');
                continue;
            }
            if ($this->config->heartbeatInterval > 0
                && $now - ($this->lastPing[$id] ?? 0.0) >= $this->config->heartbeatInterval) {
                $this->lastPing[$id] = $now;
                $this->transport->ping($id);
            }
        }
    }

    // ---- Calls into the application --------------------------------------

    private function handlerOf(WebSocketSession $session): WebSocketHandler {
        $entry = $this->handlers->find($session->getHandlerPath());
        if ($entry === null) {
            throw new \LogicException('No WebSocket handler for ' . $session->getHandlerPath());
        }
        return WebSocketHandlerRegistry::resolve($this->appCtx, $entry);
    }

    /**
     * Runs one handler call with the connection's context: the handshake
     * request bound as the current request and every hook's restore()
     * before, clear() after (also on failure). A failing restore() fails
     * closed: the call is skipped and the connection closes with 1011.
     */
    private function invoke(WebSocketHandler $handler, WebSocketSession $session, callable $call): void {
        $outerRequest = $this->appCtx->getCurrentHttpRequest();
        $this->appCtx->setCurrentHttpRequest($session->getHandshake());
        $restored = [];
        try {
            try {
                foreach ($this->interceptors->getWebSocketContextHooks() as $hook) {
                    $restored[] = $hook;
                    $hook->restore($session);
                }
            } catch (Throwable $e) {
                $this->count('websocket_errors', ['path' => $session->getHandlerPath()]);
                self::logException($e, 'WebSocket context restore failed');
                if ($session->isOpen()) {
                    $session->close(WebSocketCloseCode::INTERNAL_ERROR, 'Internal error');
                }
                return;
            }
            try {
                $call();
            } catch (Throwable $e) {
                $this->reportError($handler, $session, $e);
            }
        } finally {
            foreach (array_reverse($restored) as $hook) {
                try {
                    $hook->clear($session);
                } catch (Throwable $e) {
                    self::logException($e, 'WebSocket context clear failed');
                }
            }
            $this->appCtx->setCurrentHttpRequest($outerRequest);
        }
    }

    private function reportError(WebSocketHandler $handler, WebSocketSession $session, Throwable $e): void {
        $this->count('websocket_errors', ['path' => $session->getHandlerPath()]);
        if ($handler instanceof WebSocketErrorHandler) {
            try {
                $handler->onError($session, $e);
                return;
            } catch (Throwable $inner) {
                self::logException($inner, 'WebSocket onError failed');
            }
        }
        self::logException($e, 'WebSocket handler failed on ' . $session->getHandlerPath());
    }

    private function count(string $metric, array $labels): void {
        if ($this->metrics === null) {
            return;
        }
        try {
            if (!$this->metricsRegistered) {
                // Registered on first use, in the worker, so applications
                // without WebSockets never see these metrics.
                $this->metricsRegistered = true;
                $this->metrics->getOrRegisterCounter('websocket_connections_opened', 'WebSocket connections opened', ['path']);
                $this->metrics->getOrRegisterCounter('websocket_connections_closed', 'WebSocket connections closed', ['path', 'code']);
                $this->metrics->getOrRegisterCounter('websocket_messages_received', 'WebSocket messages received', ['path']);
                $this->metrics->getOrRegisterCounter('websocket_errors', 'WebSocket handler and protocol errors', ['path']);
            }
            $this->metrics->incr($metric, $labels);
        } catch (Throwable) {
            // Metrics never break a connection.
        }
    }
}
