<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterWebSwooleContext;
use dev\winterframework\io\process\AsyncWorkerProcess;
use dev\winterframework\io\process\ScheduleWorkerProcess;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;
use dev\winterframework\task\scheduling\ScheduledTaskPoolExecutor;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\task\TaskPoolExecutor;
use dev\winterframework\web\http\SwooleRequest;
use dev\winterframework\web\http\SwooleResponseEntity;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\HTTP\Server;
use Swoole\Process;

class WinterWebSwooleApplication extends WinterApplicationRunner implements WinterApplication {

    protected WinterWebSwooleContext $webContext;

    protected function runBootApp(): void {
        $this->webContext = new WinterWebSwooleContext(
            $this->appCtxData,
            $this->applicationContext
        );

        $this->startServer();
    }

    public function serveRequest(Request $request, Response $response): void {
        $wrapperReq = new SwooleRequest($request, $response);
        $wrapperResp = new SwooleResponseEntity($response);

        $this->webContext->getDispatcher()->dispatch($wrapperReq, $wrapperResp);
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function startServer() {
        $prop = $this->appCtxData->getPropertyContext();
        $address = $prop->get('server.address', '127.0.0.1');
        $port = $prop->get('server.port', '8080');

        $http = new Server($address, $port);

        $wServer = new WinterServer();
        $wServer->setServer($http);

        $args = $this->getServerArgs();
        if (is_array($args)) {
            $wServer->setServerArgs($args);
            $http->set($args);
        }

        $this->buildSharedServer($wServer);
        $this->beginModules();

        $http->on('request', [$this, 'serveRequest']);
        $http->on('start', function ($server) {
            self::logInfo("Http server started on $server->host:" . $server->port . ', pid:' . getmypid());
        });

        $http->on('WorkerStart', function ($server) {
            self::logInfo("Http Worker started " . ', pid:' . getmypid());
        });

        $http->on('ManagerStart', function ($server) {
            self::logInfo("Http Manager started " . ', pid:' . getmypid());
        });

        $http->on('PipeMessage', function (Server $server, $srcWorkerId, $data) {
            if (substr($data, 0, 5) === 'json:') {
                $json = json_decode(substr($data, 5), true);
                switch ($json['cmd']) {
                    case 'shutdown':
                        echo $json['message'] . "\n";
                        Process::kill($server->master_pid, SIGKILL);
                        break;

                    default:
                        self::logWarning("Worker '$srcWorkerId' sent a message to server: $data");
                        break;
                }
            } else {
                self::logWarning("Worker '$srcWorkerId' sent a message to server: $data");
            }
        });

        self::logInfo("Starting Http server on $address:$port" . ', pid:' . getmypid());
        $http->start();
    }

    protected function getServerArgs(): array {
        $args = [];
        $prop = $this->appCtxData->getPropertyContext();

        $pf = 'server.swoole.';
        $pfLen = strlen($pf);

        foreach ($prop->getAll() as $key => $value) {
            if (str_starts_with($key, $pf)) {
                $args[substr($key, $pfLen)] = $value;
            }
        }

        return $args;
    }

    protected function buildSharedServer(WinterServer $wServer): void {
        $this->appCtxData->getBeanProvider()->registerInternalBean(
            $wServer, WinterServer::class, true
        );

        $this->buildAsyncPlatform($wServer);
        $this->buildScheduledPlatform($wServer);
    }

    protected function buildAsyncPlatform(WinterServer $wServer) {
        $appCtx = $this->applicationContext;
        $appCtx->addClass(AsyncTaskPoolExecutor::class);

        /** @var AsyncTaskPoolExecutor $executor */
        $executor = $appCtx->beanByClass(AsyncTaskPoolExecutor::class);

        $this->setDefaultTaskProperties($executor);

        if ($executor->getArgsSize() < AsyncTaskPoolExecutor::ARG_SIZE) {
            $executor->setArgsSize(AsyncTaskPoolExecutor::ARG_SIZE);
        }

        for ($workerId = 1; $workerId <= $executor->getPoolSize(); $workerId++) {
            $asyncPs = new AsyncWorkerProcess($wServer, $appCtx, $executor, $workerId);
            $wServer->getServer()->addProcess($asyncPs);
        }
    }

    protected function setDefaultTaskProperties(TaskPoolExecutor $executor): void {

        if ($executor->getPoolSize() < 1) {
            $executor->setPoolSize(1);
        }

        if ($executor->getQueueCapacity() < 10) {
            $executor->setQueueCapacity(10);
        }
    }

    protected function buildScheduledPlatform(WinterServer $wServer) {
        $appCtx = $this->applicationContext;
        $appCtx->addClass(ScheduledTaskPoolExecutor::class);

        /** @var ScheduledTaskPoolExecutor $executor */
        $executor = $appCtx->beanByClass(ScheduledTaskPoolExecutor::class);
        $this->setDefaultTaskProperties($executor);

        for ($workerId = 1; $workerId <= $executor->getPoolSize(); $workerId++) {
            $schPs = new ScheduleWorkerProcess($wServer, $appCtx, $executor, $workerId);
            $wServer->getServer()->addProcess($schPs);
        }

        $this->buildScheduledRegistry($executor);
    }

    protected function buildScheduledRegistry(ScheduledTaskPoolExecutor $executor): void {
        $classes = $this->appCtxData->getResources()->getClassesByAttribute(Scheduled::class);
        $prop = $this->appCtxData->getPropertyContext();

        foreach ($classes as $clsRes) {
            foreach ($clsRes->getMethods() as $methodRes) {
                /** @var Scheduled $attr */
                $attr = $methodRes->getAttribute(Scheduled::class);
                if ($attr == null) {
                    continue;
                }
                $attr->setPropertyValues($prop);

                $executor->enqueue(
                    $clsRes->getClass()->getName(),
                    $methodRes->getMethod()->getName(),
                    [
                        'fixedDelay' => $attr->fixedDelay,
                        'fixedRate' => $attr->fixedRate,
                        'initialDelay' => $attr->initialDelay
                    ]
                );
            }
        }
    }

    protected function beginModules(): void {
        $appCtx = $this->applicationContext;

        foreach ($appCtx->getModules() as $moduleName) {
            $module = $appCtx->beanByClass($moduleName);

            if ($module instanceof WinterModule) {
                $module->begin($appCtx, $this->appCtxData);
            }
        }
    }

}