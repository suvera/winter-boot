<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterTable;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Atomic;
use Swoole\Table;

class AsyncWorkerProcess extends ServerWorkerProcess {
    use Wlf4p;

    protected AsyncTaskPoolExecutor $executor;
    protected string|int $workerId;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        AsyncTaskPoolExecutor $executor,
        string|int $workerId
    ) {
        parent::__construct($wServer, $ctx);
        $this->executor = $executor;
        $this->workerId = $workerId;

        $this->wServer->addAsyncTable(
            $this->workerId,
            $this->createWorkQueue()
        );
    }

    public function getExecutor(): AsyncTaskPoolExecutor {
        return $this->executor;
    }

    public function getWorkerId(): int|string {
        return $this->workerId;
    }

    protected function run(): void {
        self::logInfo("Async async-worker-$this->workerId has started successfully! " . getmypid());
        while (1) {
            $this->executor->executeAll($this->process, $this->workerId);
            usleep(200000);
        }
    }

    protected function createWorkQueue(): WinterTable {
        $capacity = $this->executor->getQueueCapacity();
        $argSize = $this->executor->getArgsSize();

        $table = new Table($capacity);
        $table->column('timestamp', Table::TYPE_INT);
        $table->column('className', Table::TYPE_STRING, 128);
        $table->column('methodName', Table::TYPE_STRING, 64);
        $table->column('arguments', Table::TYPE_STRING, $argSize);
        $table->create();

        self::logInfo("Shared Async Table Capacity: $capacity, Memory: " . $table->getMemorySize() . ' bytes');

        return new WinterTable($table, new Atomic(1));
    }

}