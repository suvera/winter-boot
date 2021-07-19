<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterTable;
use dev\winterframework\io\shm\ShmTable;
use dev\winterframework\task\scheduling\ScheduledTaskPoolExecutor;
use Swoole\Atomic;

class ScheduleWorkerProcess extends ServerWorkerProcess {

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

    public function getProcessType(): int {
        return ProcessType::SCHED_WORKER;
    }

    public function getProcessId(): string {
        return 'sched-' . $this->workerId;
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

        $table = new ShmTable(
            $capacity,
            [
                ['className', ShmTable::TYPE_STRING, 128],
                ['methodName', ShmTable::TYPE_STRING, 64],
                ['nextRun', ShmTable::TYPE_INT],
                ['fixedDelay', ShmTable::TYPE_INT],
                ['fixedRate', ShmTable::TYPE_INT],
                ['initialDelay', ShmTable::TYPE_INT],
                ['inProgress', ShmTable::TYPE_INT],
                ['lastRun', ShmTable::TYPE_INT]
            ]
        );

        self::logInfo("Shared Scheduling Table Capacity: $capacity, Memory: "
            . $table->getMemorySize() . ' bytes');

        return new WinterTable($table, new Atomic(1));
    }

}