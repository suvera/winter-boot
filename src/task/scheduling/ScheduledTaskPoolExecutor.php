<?php
declare(strict_types=1);

namespace dev\winterframework\task\scheduling;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\exception\SchedulingTaskException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Value;
use dev\winterframework\task\TaskPoolExecutor;
use dev\winterframework\task\TaskPoolExecutorTrait;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

#[Component]
class ScheduledTaskPoolExecutor implements TaskPoolExecutor {
    use Wlf4p;
    use TaskPoolExecutorTrait;

    #[Value('${winter.task.scheduling.poolSize}')]
    private int $poolSize = 1;

    #[Value('${winter.task.scheduling.queueCapacity}')]
    private int $queueCapacity = 50;

    #[Autowired]
    private WinterServer $server;

    #[Autowired]
    private ApplicationContext $appCtx;

    public function enqueue(string $className, string $methodName, array $args = null) {
        $tables = $this->server->getScheduledTables();
        $workerId = $this->findAvailableWorker($tables);

        if ($workerId == null) {
            self::logInfo("No Scheduling worker found!");
            return;
        }

        $fixedDelay = $args['fixedDelay'] ?? 0;
        $fixedRate = $args['fixedRate'] ?? 0;
        $initialDelay = $args['initialDelay'] ?? 0;

        $nextRun = time();
        if ($initialDelay > 0) {
            $nextRun += $initialDelay;
        }

        if (!isset($tables[$workerId])) {
            throw new SchedulingTaskException('Could not find Shared Table for Scheduling worker ' . $workerId);
        }

        $id = $tables[$workerId]->insert([
            'className' => $className,
            'methodName' => $methodName,
            'nextRun' => $nextRun,
            'fixedDelay' => $fixedDelay,
            'fixedRate' => $fixedRate,
            'initialDelay' => $initialDelay,
            'inProgress' => 0,
            'lastRun' => 0,
        ]);

        self::logInfo("Scheduled Task '$id' enqueued $className::$methodName");
    }

    public function executeAll(int $workerId) {
        $table = $this->server->getScheduledTable($workerId);
        $appCtx = $this->appCtx;

        $database = $table->getTable();
        foreach ($database as $id => $row) {
            if ($row['nextRun'] > time()) {
                continue;
            }

            $database->put($id, ['inProgress' => 1]);

            go(function () use ($table, $id, $appCtx, $workerId) {
                self::logInfo("Executing Scheduled task '$id' on sch-worker-$workerId ");

                $row = $table->getTable()->get($id);
                $className = $row['className'];
                $methodName = $row['methodName'];

                try {
                    $bean = $appCtx->beanByClass($className);
                    $bean->$methodName();
                } catch (Throwable $e) {
                    self::logException($e);
                }

                $database = $table->getTable();
                $set = [
                    'inProgress' => 0
                ];

                if ($row['fixedDelay'] > 0) {
                    $set['lastRun'] = time();
                    $set['nextRun'] = time() + $row['fixedDelay'];
                    //self::logInfo("fixedDelay Scheduling $workerId, NextRun Updated: " . time() + $row['fixedDelay']);
                }
                $database->put($id, $set);
            });

            if ($row['fixedRate'] > 0) {
                $database->put($id, [
                    'lastRun' => time(),
                    'nextRun' => time() + $row['fixedRate']
                ]);
                //self::logInfo("fixedRate Scheduling $workerId, NextRun Updated: " . time() + $row['fixedDelay']);
            }
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

}