<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\telemetry\web\OpenTelemetryWebInterceptor;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\InternalHttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;
use winterBootTests\Support\TestCase;

/**
 * 2.3.0: OpenTelemetryWebInterceptor called HttpRequest::setAttribute() /
 * getAttribute(), which did not exist: preHandle() swallowed the error with
 * the span's scope left active, and postHandle() threw on every request,
 * turning each into a 500 even with telemetry off.
 */
class OpenTelemetryWebInterceptorTest extends TestCase {
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private function request(array $headers = []): HttpRequest {
        $h = new HttpHeaders();
        foreach ($headers as $name => $value) {
            $h->set($name, $value);
        }
        return new InternalHttpRequest(new HttpRequest(), 'GET', '/orders/7', [], [], '', '', $h);
    }

    /** @return array{0: OpenTelemetryWebInterceptor, 1: InMemoryExporter} */
    private function traced(): array {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        return [new OpenTelemetryWebInterceptor($provider), $exporter];
    }

    public function testRequestAttributes(): void {
        $req = $this->request();
        $this->assertNull($req->getAttribute('x'));
        $this->assertSame('d', $req->getAttribute('x', 'd'));
        $req->setAttribute('x', null);
        $this->assertTrue($req->hasAttribute('x'), 'a null value is still set');
        $req->setAttribute('y', 5);
        $this->assertSame(['x' => null, 'y' => 5], $req->getAttributes());
        $req->removeAttribute('x');
        $this->assertFalse($req->hasAttribute('x'));
        $this->assertSame([], $this->request()->getAttributes(), 'attributes belong to one request');
    }

    public function testDisabledTelemetryLeavesRequestsAlone(): void {
        $interceptor = new OpenTelemetryWebInterceptor();
        $req = $this->request();
        $res = new ResponseEntity();
        $this->assertTrue($interceptor->preHandle($req, $res));
        $interceptor->postHandle($req, $res);
        $interceptor->afterCompletion($req, $res);
        $this->assertSame([], $req->getAttributes());
    }

    public function testServerSpanPerRequestWithIncomingTraceParent(): void {
        [$interceptor, $exporter] = $this->traced();
        $before = Context::getCurrent();
        $req = $this->request(['traceparent' => '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01']);
        $res = new ResponseEntity();

        $this->assertTrue($interceptor->preHandle($req, $res));
        $this->assertTrue($req->hasAttribute(OpenTelemetryWebInterceptor::SPAN_ATTRIBUTE));
        $this->assertFalse(Context::getCurrent() === $before, 'span scope active while handling');

        $interceptor->postHandle($req, $res);
        $res->withStatus(HttpStatus::$OK);
        $interceptor->afterCompletion($req, $res);

        $this->assertTrue(Context::getCurrent() === $before, 'scope detached afterwards');
        $this->assertSame([], $req->getAttributes(), 'span attributes removed');
        $spans = $exporter->getSpans();
        $this->assertSame(1, count($spans));
        $span = $spans[0];
        $this->assertSame('GET /orders/7', $span->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame(self::TRACE_ID, $span->getTraceId(), 'continues the caller\'s trace');
        $this->assertSame(200, $span->getAttributes()->get('http.status_code'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function testStatusOnErrorsAndClientErrors(): void {
        [$interceptor, $exporter] = $this->traced();

        $req = $this->request();
        $res = new ResponseEntity();
        $interceptor->preHandle($req, $res);
        $res->withStatus(HttpStatus::$NOT_FOUND);
        $interceptor->afterCompletion($req, $res);

        $req = $this->request();
        $res = new ResponseEntity();
        $interceptor->preHandle($req, $res);
        $res->withStatus(HttpStatus::$SERVICE_UNAVAILABLE);
        $interceptor->afterCompletion($req, $res);

        $req = $this->request();
        $res = new ResponseEntity();
        $interceptor->preHandle($req, $res);
        $res->withStatus(HttpStatus::$INTERNAL_SERVER_ERROR);
        $interceptor->afterCompletion($req, $res, new RuntimeException('secret detail'));

        [$notFound, $unavailable, $failed] = $exporter->getSpans();
        $this->assertSame(StatusCode::STATUS_UNSET, $notFound->getStatus()->getCode(), '4xx is not a server error');
        $this->assertSame(404, $notFound->getAttributes()->get('http.status_code'));
        $this->assertSame(StatusCode::STATUS_ERROR, $unavailable->getStatus()->getCode());
        $this->assertSame(StatusCode::STATUS_ERROR, $failed->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $failed->getStatus()->getDescription(), 'class, not the message');
        $this->assertSame('exception', $failed->getEvents()[0]->getName());
    }

    public function testAfterCompletionWithoutPreHandleIsHarmless(): void {
        [$interceptor, $exporter] = $this->traced();
        $interceptor->afterCompletion($this->request(), new ResponseEntity());
        $this->assertSame([], $exporter->getSpans());
    }
}
