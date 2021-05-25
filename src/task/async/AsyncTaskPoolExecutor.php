<?php
declare(strict_types=1);

namespace dev\winterframework\task\async;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Value;
use dev\winterframework\task\TaskPoolExecutor;
use dev\winterframework\util\log\Wlf4p;
use OverflowException;
use Swoole\Process;
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
    private WinterServer $server;

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

        //$workerId = (mt_rand(1, $this->poolSize) % $this->poolSize) + 1;
        //$table = $this->server->getAsyncTable($workerId);

        $min = PHP_INT_MAX;
        $table = null;
        $workerId = null;
        foreach ($this->server->getAsyncTables() as $id => $workTable) {
            $cnt = count($workTable->getTable());
            if ($min > $cnt) {
                $min = $cnt;
                $table = $workTable;
                $workerId = $id;
            }
        }

        if ($table == null) {
            self::logInfo("No Async worker found!");
            return;
        }

        $id = $table->insert([
            'className' => $className,
            'methodName' => $methodName,
            'timestamp' => time(),
            'arguments' => $argValue
        ]);

        self::logInfo("Async call id '$id' enqueued to worker-$workerId");
    }

    public function executeAll(Process $worker, int $workerId) {
        $table = $this->server->getAsyncTable($workerId);
        $appCtx = $this->appCtx;

        foreach ($table->getTable() as $id => $row) {
            go(function () use ($table, $id, $row, $appCtx, $workerId) {
                self::logInfo("Processing Async call '$id' on worker-$workerId");
                $id = intval($id);

                $table->delete($id);

                $className = $row['className'];
                $methodName = $row['methodName'];
                $args = json_decode($row['arguments'], true);

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