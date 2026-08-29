<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\telemetry\WinterTelemetry;
use Throwable;

class TraceableAspect implements WinterAspect {
    use Wlf4p;

    const OPERATION = 'Traceable';

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        if (!WinterTelemetry::isEnabled()) {
            return;
        }

        $method = $ctx->getMethod();
        $methodName = ReflectionUtil::getFqName($method);

        try {
            if (class_exists(\OpenTelemetry\API\Globals::class)) {
                $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('dev.winterframework');
                $span = $tracer->spanBuilder($methodName)->startSpan();
                $scope = $span->activate();

                $exCtx->setVariable(self::OPERATION . '_span', $span);
                $exCtx->setVariable(self::OPERATION . '_scope', $scope);
            }
        } catch (Throwable $ex) {
            self::logException($ex);
        }
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        $span = $exCtx->getVariable(self::OPERATION . '_span');
        if ($span && method_exists($span, 'recordException')) {
            $span->recordException($ex);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $ex->getMessage());
            $span->end();
        }
        $scope = $exCtx->getVariable(self::OPERATION . '_scope');
        if ($scope && method_exists($scope, 'detach')) {
            $scope->detach();
        }
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        $span = $exCtx->getVariable(self::OPERATION . '_span');
        if ($span && method_exists($span, 'setStatus')) {
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
            $span->end();
        }
        $scope = $exCtx->getVariable(self::OPERATION . '_scope');
        if ($scope && method_exists($scope, 'detach')) {
            $scope->detach();
        }
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
        $span = $exCtx->getVariable(self::OPERATION . '_span');
        if ($span && method_exists($span, 'recordException')) {
            $span->recordException($ex);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $ex->getMessage());
            $span->end();
        }
        $scope = $exCtx->getVariable(self::OPERATION . '_scope');
        if ($scope && method_exists($scope, 'detach')) {
            $scope->detach();
        }
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        $span = $exCtx->getVariable(self::OPERATION . '_span');
        if ($span && method_exists($span, 'recordException')) {
            $span->recordException($ex);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $ex->getMessage());
            $span->end();
        }
        $scope = $exCtx->getVariable(self::OPERATION . '_scope');
        if ($scope && method_exists($scope, 'detach')) {
            $scope->detach();
        }
    }
}
