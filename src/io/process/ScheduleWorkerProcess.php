<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterTable;
use dev\winterframework\task\scheduling\ScheduledTaskPoolExecutor;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Atomic;
use Swoole\Table;

class ScheduleWorkerProcess extends ServerWorkerProcess {
    use Wlf4p;

    protected ScheduledTaskPoolExecutor $executor;
    protected string|int $workerId;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        ScheduledTaskPoolExecutor $executor,
        string|int $workerId
    ) {
        parent::__construct($wServer, $ctx);
        $this->executor = $executor;
        $this->workerId = $workerId;

        $this->wServer->addScheduledTable(
            $this->workerId,
            $this->createWorkQueue()
        );
    }

    public function getExecutor(): ScheduledTaskPoolExecutor {
        return $this->executor;
    }

    public function getWorkerId(): int|string {
        return $this->workerId;
    }

    protected function run(): void {
        self::logInfo("Scheduling sch-worker-$this->workerId has started successfully! " . getmypid());
        while (1) {
            $this->executor->executeAll($this->workerId);
            \Co\System::sleep(0.2); //200000);
        }
    }

    protected function createWorkQueue(): WinterTable {
        $capacity = $this->executor->getQueueCapacity();

        $table = new Table($capacity);
        $table->column('className', Table::TYPE_STRING, 128);
        $table->column('methodName', Table::TYPE_STRING, 64);
        $table->column('nextRun', Table::TYPE_INT);
        $table->column('fixedDelay', Table::TYPE_INT);
        $table->column('fixedRate', Table::TYPE_INT);
        $table->column('initialDelay', Table::TYPE_INT);
        $table->column('inProgress', Table::TYPE_INT);
        $table->column('lastRun', Table::TYPE_INT);
        $table->create();

        self::logInfo("Shared Scheduling Table Capacity: $capacity, Memory: "
            . $table->getMemorySize() . ' bytes');

        return new WinterTable($table, new Atomic(1));
    }

}