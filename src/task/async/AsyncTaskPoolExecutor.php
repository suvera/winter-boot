<?php
declare(strict_types=1);

namespace dev\winterframework\task\async;

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

    #[Autowired]
    private AsyncQueueStoreManager $queueManager;

    #[Autowired]
    private ApplicationContext $appCtx;

    public function enqueue(string $className, string $methodName, array $args = null) {
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

        $id = $store->enqueue(AsyncQueueRecord::fromArray(0, [
            'className' => $className,
            'methodName' => $methodName,
            'timestamp' => time(),
            'arguments' => $argValue,
            'workerId' => $workerId,
        ]));

        self::logInfo("Async call id '$id' enqueued to worker-$workerId");
    }

    public function executeAll(int $workerId) {
        $store = $this->queueManager->getQueueStore($workerId);
        $appCtx = $this->appCtx;

        while ($record = $store->dequeue()) {
            go(function () use ($store, $record, $appCtx, $workerId) {
                self::logInfo("Processing Async call '" . $record->getId() . "' on async-worker-$workerId");
                $className = $record->getClassName();
                $methodName = $record->getMethodName();
                $args = json_decode($record->getArguments(), true);

                try {
                    $bean = $appCtx->beanByClass($className);
                    $bean->$methodName(...$args);
                } catch (Throwable $e) {
                    self::logException($e);
                }
            });
        }
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