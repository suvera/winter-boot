<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\core\aop\ex\AopException;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

/**
 * NativeAopDriver runs the aspect protocol (begin/stop/commit/failed,
 * async enqueue) for calls intercepted by the winter_boot extension,
 * without any generated subclass:
 *
 *  - begin() runs aspectBegin(); stopExecution() short-circuits the body
 *    (proceed=false + result), otherwise the body runs (proceed=true).
 *  - finish() runs aspectFailed() on body exceptions or aspectCommit() on
 *    return values. Commit failures are logged and swallowed, except those
 *    of PropagatesCommitFailure aspects (transactions), which are rethrown.
 *  - async methods enqueue under their real name and return null, exactly
 *    like the generated enqueue stub.
 *
 * The extension calls begin()/finish() by name; aspect semantics stay
 * entirely in PHP here. This class is pure PHP and never calls the
 * extension itself, so it is safe to load with or without winter_boot.
 *
 * DispatcherServlet (step 6.2) intentionally repeats this protocol inline
 * for #[RestController] endpoints, which are never natively advised; see
 * the comment there for why and how the two deliberately differ. Keep any
 * protocol change in sync with it.
 */
final class NativeAopDriver {
    use Wlf4p;

    private static ?AopInterceptorRegistry $registry = null;
    private static ?ApplicationContext $appCtx = null;
    private static bool $nativeActive = false;
    /** @var ?array{0: string, 1: string} single-shot worker bypass token */
    private static ?array $bypassOnce = null;

    public static function boot(
        AopInterceptorRegistry $registry,
        ApplicationContext $appCtx
    ): void {
        self::$registry = $registry;
        self::$appCtx = $appCtx;
    }

    public static function setNativeActive(bool $active = true): void {
        self::$nativeActive = $active;
    }

    public static function isNativeActive(): bool {
        return self::$nativeActive;
    }

    /**
     * Arm a single body-run for one worker-dispatched async call. The token
     * is consumed by the next matching begin() only, so nested async calls
     * made by the job body still enqueue normally.
     */
    public static function bypassOnce(string $class, string $method): void {
        self::$bypassOnce = [$class, $method];
    }

    public static function clearBypass(): void {
        self::$bypassOnce = null;
    }

    /**
     * @return array{proceed: bool, value?: mixed, exCtx?: AopExecutionContext, interceptor?: AopInterceptor}
     */
    public static function begin(
        mixed $target,
        string $class,
        string $method,
        array $args
    ): array {
        if (self::$registry === null) {
            throw new AopException('NativeAopDriver has not been booted with an AopInterceptorRegistry');
        }

        /** @var AopInterceptor $interceptor */
        $interceptor = self::$registry->get($class, $method);

        $workerReentry = self::$bypassOnce === [$class, $method];
        if ($workerReentry) {
            self::$bypassOnce = null;
        }

        if ($interceptor->getMethod()->isAsyncProxy() && !$workerReentry) {
            if (self::$appCtx === null) {
                throw new AopException('NativeAopDriver has not been booted with an ApplicationContext');
            }
            $executor = self::$appCtx->beanByClass(AsyncTaskPoolExecutor::class);
            $executor->enqueue($class, $method, $args);
            return ['proceed' => false, 'value' => null];
        }

        $exCtx = new AopExecutionContext($target, $args);

        // Exceptions from aspectBegin() propagate unchanged, like the proxy.
        $interceptor->aspectBegin($exCtx);
        $exCtx->setBeginDone();

        if ($exCtx->isStopExecution()) {
            return ['proceed' => false, 'value' => $exCtx->getResult()];
        }

        return [
            'proceed' => true,
            'value' => null,
            'exCtx' => $exCtx,
            'interceptor' => $interceptor,
        ];
    }

    public static function finish(
        AopExecutionContext $exCtx,
        AopInterceptor $interceptor,
        string $outcome,
        mixed $payload
    ): void {
        if ($outcome === 'threw' && $payload instanceof Throwable) {
            $exCtx->setException($payload);
            $exCtx->setFailed();

            $interceptor->aspectFailed($exCtx, $payload);
            // The extension rethrows the original unchanged.
            self::logException($payload, 'AOP invocation failed on ' . self::label($interceptor));
            return;
        }

        $exCtx->setSuccess();
        $exCtx->setResult($payload);

        try {
            $interceptor->aspectCommit($exCtx, $payload);
            $exCtx->setSuccess();
        } catch (Throwable $e) {
            $exCtx->setException($e);
            $exCtx->setCommitFailed();

            if ($exCtx->getPropagatedCommitFailure() === $e) {
                // e.g. the transaction did not commit: the extension leaves
                // this pending, so the caller gets it instead of the result.
                throw $e;
            }
            self::logException($e);
        }
    }

    private static function label(AopInterceptor $interceptor): string {
        return $interceptor->getClass()->getClass()->getName()
            . '::' . $interceptor->getMethod()->getMethod()->getShortName() . '()';
    }
}
