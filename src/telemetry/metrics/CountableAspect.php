<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\metrics;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\telemetry\WinterTelemetry;
use Throwable;

class CountableAspect implements WinterAspect {
    use Wlf4p;

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        if (!WinterTelemetry::isEnabled()) {
            return;
        }

        /** @var Countable $stereo */
        $stereo = $ctx->getStereoType();
        $methodName = ReflectionUtil::getFqName($ctx->getMethod());

        try {
            $metrics = new OpenTelemetryMetrics();
            $metrics->add($stereo->name, $stereo->value, [
                'method' => $methodName
            ]);
        } catch (Throwable $ex) {
            self::logException($ex);
        }
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }
}
