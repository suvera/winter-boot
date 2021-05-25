<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterTable;
use dev\winterframework\core\context\WinterWebSwooleContext;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;
use dev\winterframework\web\http\SwooleRequest;
use Swoole\Atomic;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\HTTP\Server;
use Swoole\Process;
use Swoole\Table;

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
        //self::logInfo(print_r($request, true));

        $wrapperReq = new SwooleRequest($request, $response);

        $this->webContext->getDispatcher()->dispatch($wrapperReq);
    }

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

        $http->on('request', [$this, 'serveRequest']);
        $http->on('start', function ($server) {
            self::logInfo("Http server started on {$server->host}:" . $server->port);
        });

        self::logInfo("Starting Http server on $address:$port");
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
    }

    protected function buildAsyncPlatform(WinterServer $wServer) {
        $appCtx = $this->applicationContext;
        $appCtx->addClass(AsyncTaskPoolExecutor::class);

        /** @var AsyncTaskPoolExecutor $executor */
        $executor = $appCtx->beanByClass(AsyncTaskPoolExecutor::class);

        if ($executor->getPoolSize() < 1) {
            $executor->setPoolSize(1);
        }

        if ($executor->getQueueCapacity() < 10) {
            $executor->setQueueCapacity(10);
        }

        if ($executor->getArgsSize() < AsyncTaskPoolExecutor::ARG_SIZE) {
            $executor->setArgsSize(AsyncTaskPoolExecutor::ARG_SIZE);
        }

        for ($workerId = 1; $workerId <= $executor->getPoolSize(); $workerId++) {
            $wServer->addAsyncTable(
                $workerId,
                $this->createAsyncTaskTable($executor->getQueueCapacity(), $executor->getArgsSize())
            );

            $wServer->getServer()->addProcess(
                new Process(function ($process) use ($executor, $workerId) {
                    self::logInfo("Async worker-$workerId has started successfully! ");
                    while (1) {
                        $executor->executeAll($process, $workerId);
                        usleep(200000);
                    }
                })
            );
        }
    }

    protected function createAsyncTaskTable(int $capacity, int $argSize): WinterTable {
        $table = new Table($capacity);
        $table->column('timestamp', Table::TYPE_INT);
        $table->column('className', Table::TYPE_STRING, 128);
        $table->column('methodName', Table::TYPE_STRING, 64);
        $table->column('arguments', Table::TYPE_STRING, $argSize);
        $table->create();

        self::logInfo("Shared Table Capacity: $capacity, Memory: " . $table->getMemorySize() . ' bytes');

        return new WinterTable($table, new Atomic(1));
    }
}