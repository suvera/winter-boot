<?php
/** @noinspection PhpPropertyOnlyWrittenInspection */
declare(strict_types=1);

namespace dev\winterframework\task\async;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Value;
use dev\winterframework\util\async\AsyncQueueStore;

#[Component]
class AsyncQueueStoreManager {

    #[Value('${winter.task.async.argsSize}')]
    private int $argsSize = AsyncTaskPoolExecutor::ARG_SIZE;

    #[Value('${winter.task.async.queueCapacity}')]
    private int $queueCapacity = 50;

    #[Value('${winter.task.async.queueStorage.handler}')]
    private string $queueStorageHandler = "\\dev\\winterframework\\util\\async\\AsyncInMemoryQueue";

    #[Autowired]
    private ApplicationContext $appCtx;
    /**
     * @var AsyncQueueStore[]
     */
    private array $workerStores = [];

    public function getQueueStore(int $workerId): ?AsyncQueueStore {
        return $this->workerStores[$workerId] ?? null;
    }

    public function addQueueStoreDefault(int $workerId): void {
        $cls = $this->queueStorageHandler;
        $this->workerStores[$workerId] = ReflectionUtil::createAutoWiredObject(
            $this->appCtx,
            new RefKlass($cls),
            $this->appCtx,
            $workerId,
            $this->queueCapacity,
            $this->argsSize
        );
    }

    public function addQueueStore(int $workerId, AsyncQueueStore $store): void {
        $this->workerStores[$workerId] = $store;
    }

    public function deleteQueueStore(int $workerId): void {
        unset($this->workerStores[$workerId]);
    }

    public function getWorkerStores(): array {
        return $this->workerStores;
    }

    public function findAvailableWorker(): ?int {
        $min = PHP_INT_MAX;
        $workerId = null;

        foreach ($this->workerStores as $id => $queue) {
            $cnt = $queue->size();
            if ($min > $cnt) {
                $min = $cnt;
                $workerId = $id;
            }
        }

        return $workerId;
    }

}