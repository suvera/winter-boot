<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\util\log\LoggerManager;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use Monolog\Handler\TestHandler;
use winterBootTests\Support\TestCase;

final class TraceStubRequest extends HttpRequest {
    public function __construct(private string $stubMethod = 'GET', private string $stubUri = '/api/users') {
    }

    public function getMethod(): string {
        return $this->stubMethod;
    }

    public function getUri(): string {
        return $this->stubUri;
    }
}

/**
 * Request/response trace logging (`winter.web.request.enableTrace`).
 *
 * Covers the new application.yml parameter end to end: yaml value flows
 * into the property context, gates both trace lines, arrival logs instantly
 * with method + URI only, and completion logs status + time taken — with no
 * raw attacker-controlled bytes in either line.
 */
final class RequestTraceLoggingTest extends TestCase {

    private const ENABLED_YML = "winter:\n  web:\n    request:\n      enableTrace: true\n";

    private string $tmpDir = '';

    private function makeConfigDir(string $yml): string {
        $dir = sys_get_temp_dir() . '/wbtrace-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/application.yml', $yml);
        $this->tmpDir = $dir;
        return $dir;
    }

    public function __destruct() {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            array_map(unlink(...), glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    private function servletWithFlag(string $yml): DispatcherServlet {
        $propCtx = new WinterPropertyContext([$this->makeConfigDir($yml)]);
        $ctxData = new ApplicationContextData();
        $ctxData->setPropertyContext($propCtx);

        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(DispatcherServlet::class, 'ctxData');
        $prop->setAccessible(true);
        $prop->setValue($servlet, $ctxData);
        return $servlet;
    }

    /** @return list<array> */
    private function capture(string $method, DispatcherServlet $servlet, mixed ...$args): array {
        $handler = new TestHandler();
        $logger = LoggerManager::getLogger();
        $logger->pushHandler($handler);
        try {
            $ref = new \ReflectionMethod(DispatcherServlet::class, $method);
            $ref->setAccessible(true);
            $ref->invoke($servlet, ...$args);
        } finally {
            $logger->popHandler();
        }
        return $handler->getRecords();
    }

    // yaml parameter resolves through the property context when enabled.
    public function testYamlFlagEnabled(): void {
        $propCtx = new WinterPropertyContext([$this->makeConfigDir(self::ENABLED_YML)]);
        $this->assertTrue($propCtx->getBool('winter.web.request.enableTrace', false));
    }

    // Missing key keeps traces off by default.
    public function testYamlFlagDefaultsToFalse(): void {
        $propCtx = new WinterPropertyContext([$this->makeConfigDir("server:\n  port: 8080\n")]);
        $this->assertFalse($propCtx->getBool('winter.web.request.enableTrace', false));
    }

    // Arrival: one line instantly, method + URI only, no status/duration yet.
    public function testStartLoggedOnArrival(): void {
        $servlet = $this->servletWithFlag(self::ENABLED_YML);
        $records = $this->capture('logRequestReceived', $servlet, new TraceStubRequest('GET', '/api/users'));
        $this->assertSame(1, count($records));
        $message = $records[0]['message'];
        $this->assertTrue(str_contains($message, 'TRACE START GET /api/users'), $message);
        $this->assertFalse(str_contains($message, '->'), 'arrival line must not carry a status');
    }

    // Completion: method + URI + status + time taken, marked FINISH.
    public function testFinishLoggedOnCompletion(): void {
        HttpStatus::init();
        $servlet = $this->servletWithFlag(self::ENABLED_YML);
        $records = $this->capture(
            'logRequestCompleted',
            $servlet,
            new TraceStubRequest('GET', '/api/users'),
            ResponseEntity::ok(),
            microtime(true) - 0.025
        );
        $this->assertSame(1, count($records));
        $message = $records[0]['message'];
        $this->assertTrue(str_contains($message, 'TRACE FINISH GET /api/users -> 200'), $message);
        $this->assertTrue(str_contains($message, 'ms)'), $message);
    }

    // Explicit error status is used verbatim (error paths set it during render).
    public function testFinishUsesExplicitErrorStatus(): void {
        HttpStatus::init();
        $servlet = $this->servletWithFlag(self::ENABLED_YML);
        $records = $this->capture(
            'logRequestCompleted',
            $servlet,
            new TraceStubRequest('POST', '/api/orders'),
            new ResponseEntity(),
            microtime(true),
            404
        );
        $this->assertSame(1, count($records));
        $this->assertTrue(str_contains($records[0]['message'], 'TRACE FINISH POST /api/orders -> 404'));
    }

    // Disabled: neither arrival nor completion logs anything.
    public function testNoLogsWhenDisabled(): void {
        $servlet = $this->servletWithFlag("server:\n  port: 8080\n");
        $this->assertSame(0, count($this->capture('logRequestReceived', $servlet, new TraceStubRequest())));
        $this->assertSame(0, count($this->capture(
            'logRequestCompleted',
            $servlet,
            new TraceStubRequest(),
            ResponseEntity::ok(),
            microtime(true)
        )));
    }

    // Attacker-controlled URI bytes never reach either log line raw.
    public function testUrisSanitizedInBothLines(): void {
        $servlet = $this->servletWithFlag(self::ENABLED_YML);
        $evil = new TraceStubRequest('GET', "/api/users\r\nX-Injected: evil");
        $start = $this->capture('logRequestReceived', $servlet, $evil);
        $finish = $this->capture('logRequestCompleted', $servlet, $evil, ResponseEntity::ok(), microtime(true));
        $this->assertSame(1, count($start));
        $this->assertSame(1, count($finish));
        foreach ([$start[0]['message'], $finish[0]['message']] as $message) {
            $this->assertFalse(str_contains($message, "\r"), $message);
            $this->assertFalse(str_contains($message, "\n"), $message);
        }
    }
}
