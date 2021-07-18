<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;

class AsyncWorkerProcess extends ServerWorkerProcess {

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
    }

    public function getExecutor(): AsyncTaskPoolExecutor {
        return $this->executor;
    }

    public function getWorkerId(): int|string {
        return $this->workerId;
    }

    public function getProcessType(): int {
        return ProcessType::ASYNC_WORKER;
    }

    public function getProcessId(): string {
        return 'async-' . $this->workerId;
    }

    protected function run(): void {

        self::logInfo("Async async-worker-$this->workerId has started successfully! " . getmypid());
        while (1) {
            $this->executor->executeAll($this->workerId);
            \Co\System::sleep(0.2); //200000);
        }
    }

}