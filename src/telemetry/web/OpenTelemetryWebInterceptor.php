<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\web;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\telemetry\WinterTelemetry;
use Throwable;

class OpenTelemetryWebInterceptor implements HandlerInterceptor {

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        if (!WinterTelemetry::isEnabled()) {
            return true;
        }

        try {
            if (class_exists(\OpenTelemetry\API\Globals::class)) {
                $carrier = [];
                foreach ($request->getHeaders() as $key => $value) {
                    $carrier[strtolower($key)] = is_array($value) ? implode(',', $value) : $value;
                }

                $parentContext = (new \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator())->extract($carrier);
                $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('dev.winterframework.web');

                $spanName = $request->getMethod() . ' ' . $request->getUri();
                $span = $tracer->spanBuilder($spanName)
                    ->setParent($parentContext)
                    ->setAttribute('http.method', $request->getMethod())
                    ->setAttribute('http.url', $request->getUri())
                    ->startSpan();

                $scope = $span->activate();

                $request->setAttribute('_otel_span', $span);
                $request->setAttribute('_otel_scope', $scope);
            }
        } catch (Throwable) {
        }

        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
        $span = $request->getAttribute('_otel_span');
        if ($span && method_exists($span, 'setAttribute')) {
            $span->setAttribute('http.status_code', $response->getStatus());
            if ($response->getStatus() >= 400) {
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
            } else {
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
            }
        }
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
        $span = $request->getAttribute('_otel_span');
        if ($span && method_exists($span, 'end')) {
            if ($ex && method_exists($span, 'recordException')) {
                $span->recordException($ex);
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $ex->getMessage());
            }
            $span->end();
        }

        $scope = $request->getAttribute('_otel_scope');
        if ($scope && method_exists($scope, 'detach')) {
            $scope->detach();
        }
    }
}
