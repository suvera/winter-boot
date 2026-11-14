<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\web;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\telemetry\WinterTelemetry;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * One server span per request: started in preHandle() (parented on an
 * incoming W3C `traceparent`), activated so spans created while handling
 * the request become its children, and ended in afterCompletion(), which
 * runs on every path (also errors and vetoes), with the response status.
 *
 * Tracing never breaks a request: every OpenTelemetry call is contained.
 * The span and its scope are kept as request attributes
 * ({@see SPAN_ATTRIBUTE}, {@see SCOPE_ATTRIBUTE}), so concurrent requests
 * never share them.
 *
 * Register it in a WebMvcConfigurer:
 * `$registry->addInterceptor(new OpenTelemetryWebInterceptor(), '.*');`
 */
class OpenTelemetryWebInterceptor implements HandlerInterceptor {
    public const SPAN_ATTRIBUTE = 'winter.otel.span';
    public const SCOPE_ATTRIBUTE = 'winter.otel.scope';

    /**
     * @param TracerProviderInterface|null $tracerProvider the provider to use
     *     (tracing is then on); null uses the global provider when telemetry
     *     is enabled (OpenTelemetryModule or the opentelemetry extension).
     */
    public function __construct(
        private ?TracerProviderInterface $tracerProvider = null
    ) {
    }

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        if (!$this->isTracing()) {
            return true;
        }
        $span = null;
        $scope = null;
        try {
            $carrier = [];
            foreach ($request->getHeaders()->getAll() as $name => $values) {
                $carrier[strtolower((string)$name)] = implode(',', (array)$values);
            }
            $parent = TraceContextPropagator::getInstance()->extract($carrier);
            $tracer = ($this->tracerProvider ?? Globals::tracerProvider())->getTracer('dev.winterframework.web');

            $span = $tracer->spanBuilder($request->getMethod() . ' ' . $request->getUri())
                ->setParent($parent)
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setAttribute('http.method', $request->getMethod())
                ->setAttribute('http.url', $request->getUri())
                ->startSpan();
            $scope = $span->activate();

            $request->setAttribute(self::SPAN_ATTRIBUTE, $span);
            $request->setAttribute(self::SCOPE_ATTRIBUTE, $scope);
        } catch (Throwable) {
            // Half-started tracing must not leak an active scope into later requests.
            try {
                $scope?->detach();
                $span?->end();
            } catch (Throwable) {
            }
            $request->removeAttribute(self::SPAN_ATTRIBUTE);
            $request->removeAttribute(self::SCOPE_ATTRIBUTE);
        }
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
        // Status is recorded in afterCompletion(), which also runs for errors.
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
        $span = $request->getAttribute(self::SPAN_ATTRIBUTE);
        $scope = $request->getAttribute(self::SCOPE_ATTRIBUTE);
        $request->removeAttribute(self::SPAN_ATTRIBUTE);
        $request->removeAttribute(self::SCOPE_ATTRIBUTE);
        if (!$span instanceof SpanInterface) {
            return;
        }
        try {
            $status = $response->getStatus()->getValue();
            $span->setAttribute('http.status_code', $status);
            if ($ex !== null) {
                $span->recordException($ex);
                $span->setStatus(StatusCode::STATUS_ERROR, $ex::class);
            } elseif ($status >= 500) {
                // Server spans: 5xx is an error, 4xx is the client's (status left unset).
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
            $span->end();
        } catch (Throwable) {
        } finally {
            try {
                if ($scope instanceof ScopeInterface) {
                    $scope->detach();
                }
            } catch (Throwable) {
            }
        }
    }

    private function isTracing(): bool {
        if (!interface_exists(TracerProviderInterface::class)) {
            return false;
        }
        return $this->tracerProvider !== null || WinterTelemetry::isEnabled();
    }
}
