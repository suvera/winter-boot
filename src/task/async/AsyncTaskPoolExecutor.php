<?php
declare(strict_types=1);

namespace dev\winterframework\task\async;

use dev\winterframework\core\aop\NativeAopDriver;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Value;
use dev\winterframework\task\TaskPoolExecutor;
use dev\winterframework\util\async\AsyncQueueRecord;
use dev\winterframework\util\log\Wlf4p;
use OverflowException;
use Throwable;

#[Component]
class AsyncTaskPoolExecutor implements TaskPoolExecutor {
    use Wlf4p;

    const ARG_SIZE = 2048;

    #[Value('${winter.task.async.poolSize}')]
    private int $poolSize = 1;

    #[Value('${winter.task.async.queueCapacity}')]
    private int $queueCapacity = 50;

    #[Value('${winter.task.async.argsSize}')]
    private int $argsSize = self::ARG_SIZE;

    /**
     * Max jobs one async worker runs at once (0 = unlimited, the 2.x
     * behaviour). Jobs beyond it stay queued until a running one finishes.
     */
    #[Value('${winter.task.async.maxConcurrency}', 0)]
    private int $maxConcurrency = 0;

    /** @var array<int, int> worker id => running jobs */
    private array $running = [];

    #[Autowired]
    private AsyncQueueStoreManager $queueManager;

    #[Autowired]
    private ApplicationContext $appCtx;

    public function enqueue(string $className, string $methodName, ?array $args = null) {
        $argValue = '{}';
        if ($args) {
            $argValue = json_encode($args);
            if (strlen($argValue) > $this->argsSize) {
                throw new OverflowException('Arguments size is too large, exceeds '
                    . $this->argsSize . ' bytes');
            }
        }

        $workerId = $this->queueManager->findAvailableWorker();

        if ($workerId == null) {
            self::logInfo("No Async worker found!");
            return;
        }
        $store = $this->queueManager->getQueueStore($workerId);

        try {
            $id = $store->enqueue(AsyncQueueRecord::fromArray(0, [
                'className' => $className,
                'methodName' => $methodName,
                'timestamp' => time(),
                'arguments' => $argValue,
                'workerId' => $workerId,
            ]));
        } catch (OverflowException $e) {
            // Dropped, but loudly; the caller's request is not failed.
            self::logError("Async call $className::$methodName dropped: " . $e->getMessage());
            return;
        }

        self::logDebug("Async call id '$id' enqueued to worker-$workerId");
    }

    public function executeAll(int $workerId) {
        $store = $this->queueManager->getQueueStore($workerId);
        $appCtx = $this->appCtx;

        while ($this->hasCapacity($workerId) && ($record = $store->dequeue())) {
            $this->running[$workerId] = ($this->running[$workerId] ?? 0) + 1;
            go(function () use ($store, $record, $appCtx, $workerId) {
                self::logDebug("Processing Async call '" . $record->getId() . "' on async-worker-$workerId");
                $className = $record->getClassName();
                $methodName = $record->getMethodName();
                $args = json_decode($record->getArguments(), true);

                try {
                    $bean = $appCtx->beanByClass($className);
                    // Native path: jobs are enqueued under the real method
                    // name, so this call would re-enqueue instead of running.
                    // The single-shot token makes exactly this invocation run
                    // the body (aspects included, like the proxy's Original
                    // twin); nested async calls still enqueue normally. It is
                    // a no-op when native interception is inactive.
                    NativeAopDriver::bypassOnce($className, $methodName);
                    try {
                        $bean->$methodName(...$args);
                    } finally {
                        NativeAopDriver::clearBypass();
                    }
                } catch (Throwable $e) {
                    self::logException($e);
                } finally {
                    $this->running[$workerId]--;
                }
            });
        }
    }

    public function hasCapacity(int $workerId): bool {
        return $this->maxConcurrency <= 0 || ($this->running[$workerId] ?? 0) < $this->maxConcurrency;
    }

    public function getMaxConcurrency(): int {
        return $this->maxConcurrency;
    }

    public function setMaxConcurrency(int $maxConcurrency): void {
        $this->maxConcurrency = max(0, $maxConcurrency);
    }

    public function getPoolSize(): int {
        return $this->poolSize;
    }

    public function getQueueCapacity(): int {
        return $this->queueCapacity;
    }

    public function setPoolSize(int $poolSize): void {
        $this->poolSize = $poolSize;
    }

    public function setQueueCapacity(int $queueCapacity): void {
        $this->queueCapacity = $queueCapacity;
    }

    public function getArgsSize(): int {
        return $this->argsSize;
    }

    public function setArgsSize(int $argsSize): void {
        $this->argsSize = $argsSize;
    }

}