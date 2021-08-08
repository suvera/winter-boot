<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\context\WinterWebSwooleContext;
use dev\winterframework\io\kv\KvClient;
use dev\winterframework\io\kv\KvConfig;
use dev\winterframework\io\kv\KvServerProcess;
use dev\winterframework\io\kv\KvTemplate;
use dev\winterframework\io\metrics\prometheus\KvAdapter;
use dev\winterframework\io\process\AsyncWorkerProcess;
use dev\winterframework\io\process\ProcessType;
use dev\winterframework\io\process\ScheduleWorkerProcess;
use dev\winterframework\io\queue\QueueClient;
use dev\winterframework\io\queue\QueueConfig;
use dev\winterframework\io\queue\QueueServerProcess;
use dev\winterframework\io\queue\QueueSharedTemplate;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\stereotype\cli\DaemonThread;
use dev\winterframework\stereotype\task\EnableAsync;
use dev\winterframework\stereotype\task\EnableScheduling;
use dev\winterframework\task\async\AsyncQueueStoreManager;
use dev\winterframework\task\async\AsyncTaskPoolExecutor;
use dev\winterframework\task\scheduling\ScheduledTaskPoolExecutor;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\task\TaskPoolExecutor;
use dev\winterframework\web\http\SwooleRequest;
use dev\winterframework\web\http\SwooleResponseEntity;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\HTTP\Server;
use Swoole\Process;
use function Ramsey\Uuid\v4;

class WinterWebSwooleApplication extends WinterApplicationRunner implements WinterApplication {

    protected WinterWebSwooleContext $webContext;
    protected WinterCliArguments $args;

    public function __construct() {
        $this->args = new WinterCliArguments();
        $configDir = $this->args->get('configDir');
        if ($configDir) {
            $this->configDir = $configDir;
        }
        parent::__construct();
    }

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

    protected function startServer() {
        $wServer = new WinterServer($this->applicationContext, $this->appCtxData);

        $args = $this->getServerArgs();
        if (is_array($args)) {
            $wServer->setServerArgs($args);
        }

        $this->buildSharedServer($wServer);
        if ($this->bootApp->getAttribute(EnableAsync::class) != null) {
            $this->buildAsyncPlatform($wServer);
        }
        if ($this->bootApp->getAttribute(EnableScheduling::class) != null) {
            $this->buildScheduledPlatform($wServer);
        }

        $wServer->addEventCallback('request', [$this, 'serveRequest']);

        $wServer->addEventCallback('start', function (Server $server) use ($wServer) {
            posix_setpgid(getmypid(), $server->master_pid);
            $wServer->addPid('master', $server->master_pid, ProcessType::MASTER);
            self::logInfo("Http server started on $server->host:" . $server->port . ', pid:' . getmypid()
                . ', master_pid:' . $server->master_pid);
        });

        $wServer->addEventCallback('WorkerStart', function (Server $server, int $workerId) use ($wServer) {
            posix_setpgid(getmypid(), $server->master_pid);

            /** @var IdleCheckRegistry $idleCheck */
            $idleCheck = $wServer->getAppCtx()->beanByClass(IdleCheckRegistry::class);
            $idleCheck->initialize();

            if ($workerId < $server->setting['worker_num']) {
                $psType = ProcessType::HTTP_WORKER;
                self::logInfo("Http Worker($workerId) started " . ', pid:' . getmypid()
                    . ', master_pid:' . $server->master_pid);
            } else {
                $psType = ProcessType::TASK_WORKER;
                self::logInfo("Task Worker($workerId) started " . ', pid:' . getmypid()
                    . ', master_pid:' . $server->master_pid);
            }

            $wServer->addPid('worker-' . $workerId, getmypid(), $psType);
        });

        $wServer->addEventCallback('ManagerStart', function (Server $server) use ($wServer) {
            posix_setpgid(getmypid(), $server->master_pid);
            $wServer->addPid('manager', getmypid(), ProcessType::MANAGER);

            /** @var IdleCheckRegistry $idleCheck */
            $idleCheck = $wServer->getAppCtx()->beanByClass(IdleCheckRegistry::class);
            $idleCheck->initialize();
            self::logInfo("Http Manager started " . ', pid:' . getmypid()
                . ', master_pid:' . $server->master_pid);
        });

        $wServer->addEventCallback('PipeMessage', function (Server $server, $srcWorkerId, $data) {
            if (str_starts_with($data, 'json:')) {
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

        $this->buildKvStore($wServer);
        $this->buildQueueStore($wServer);
        $this->beginModules();
        $this->beginDaemonThreads($wServer);
        $this->onApplicationReady();

        $wServer->start();
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
    }

    protected function buildAsyncPlatform(WinterServer $wServer) {
        $appCtx = $this->applicationContext;
        $appCtx->addClass(AsyncQueueStoreManager::class);
        $appCtx->addClass(AsyncTaskPoolExecutor::class);

        /** @var AsyncTaskPoolExecutor $executor */
        $executor = $appCtx->beanByClass(AsyncTaskPoolExecutor::class);
        /** @var AsyncQueueStoreManager $queueManager */
        $queueManager = $appCtx->beanByClass(AsyncQueueStoreManager::class);

        $this->setDefaultTaskProperties($executor);

        if ($executor->getArgsSize() < AsyncTaskPoolExecutor::ARG_SIZE) {
            $executor->setArgsSize(AsyncTaskPoolExecutor::ARG_SIZE);
        }

        for ($workerId = 1; $workerId <= $executor->getPoolSize(); $workerId++) {
            $queueManager->addQueueStoreDefault($workerId);
            $asyncPs = new AsyncWorkerProcess($wServer, $appCtx, $executor, $workerId);
            $wServer->addProcess($asyncPs);
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
            $wServer->addProcess($schPs);
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


    protected function showBanner(): void {
        $bannerFile = $this->propertyCtx->getStr('banner.location', '');

        if ($bannerFile && is_file($bannerFile)) {
            $bannerText = file_get_contents($bannerFile);
        } else {
            $bannerText = <<<EOQ
  _      _______  _______________      ___  ____  ____  ______
 | | /| / /  _/ |/ /_  __/ __/ _ \    / _ )/ __ \/ __ \/_  __/
 | |/ |/ // //    / / / / _// , _/   / _  / /_/ / /_/ / / /   
 |__/|__/___/_/|_/ /_/ /___/_/|_|   /____/\____/\____/ /_/    

\${winterBoot.name}: \${winterBoot.version}
\${app.name}: \${app.version}
\${php.name}: \${php.version}
\${swoole.name}: \${swoole.version}
\${rdkafka.name}: \${rdkafka.version}
\${redis.name}: \${redis.version}
EOQ;
        }

        $appName = $GLOBALS['winter.application.name'] ?? $this->propertyCtx->getStr('winter.application.name', '');
        $appVersion = $GLOBALS['winter.application.version'] ?? $this->propertyCtx->getStr('winter.application.version', '');

        $labels = [
            '${winterBoot.name}' => 'Winter Boot',
            '${winterBoot.version}' => $this->getBootVersion(),
            '${app.name}' => $appName,
            '${app.version}' => $appVersion,
            '${php.name}' => 'PHP',
            '${php.version}' => phpversion() . ', ' . php_sapi_name(),
        ];
        $extensions = ['swoole', 'rdkafka', 'redis'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $labels['${' . $ext . '.name}'] = ucwords($ext);
                $labels['${' . $ext . '.version}'] = phpversion($ext);
            }
        }

        $bannerText = str_replace(array_keys($labels), array_values($labels), $bannerText);
        $bannerText = preg_replace('/[\s:]+$/', '', $bannerText);

        $this->console->info("\n" . $bannerText);
    }

    protected function buildKvStore(WinterServer $wServer): void {
        $prop = $this->appCtxData->getPropertyContext();
        $port = $prop->getInt('winter.kv.port', 0);
        $address = $prop->getStr('winter.kv.address', '');
        $token = $prop->getStr('winter.kv.token', v4());
        $diskPath = $prop->getStr('winter.kv.diskPath', '');
        $phpBinary = $prop->getStr('winter.phpBinary', '');
        if ($port <= 0) {
            return;
        }
        $config = new KvConfig(
            $token,
            $port,
            $address ?: null,
            $phpBinary ?: null,
            $diskPath
        );

        $kvTpl = new KvClient($config);
        $this->appCtxData->getBeanProvider()->registerInternalBean(
            $kvTpl, KvTemplate::class, false
        );
        $this->appCtxData->getBeanProvider()->registerInternalBean(
            new KvAdapter($kvTpl), KvAdapter::class, false
        );

        $kvPs = new KvServerProcess($wServer, $this->applicationContext, $config);
        $wServer->addProcess($kvPs);
    }

    protected function buildQueueStore(WinterServer $wServer): void {
        $prop = $this->appCtxData->getPropertyContext();
        $port = $prop->getInt('winter.queue.port', 0);
        $address = $prop->getStr('winter.queue.address', '');
        $token = $prop->getStr('winter.queue.token', v4());
        $diskPath = $prop->getStr('winter.kv.diskPath', '');
        $phpBinary = $prop->getStr('winter.phpBinary', '');
        if ($port <= 0) {
            return;
        }
        $config = new QueueConfig(
            $token,
            $port,
            $address ?: null,
            $phpBinary ?: null,
            $diskPath
        );

        $this->appCtxData->getBeanProvider()->registerInternalBean(
            new QueueClient($config), QueueSharedTemplate::class, false
        );

        $ps = new QueueServerProcess($wServer, $this->applicationContext, $config);
        $wServer->addProcess($ps);
    }

    protected function beginDaemonThreads(WinterServer $wServer): void {
        $daemonClasses = $this->resources->getClassesByAttribute(DaemonThread::class);
        /** @var ClassResource[] $resources */
        $resources = [];
        foreach ($daemonClasses as $clsRes) {
            /** @var ClassResource $clsRes */
            $resources[$clsRes->getClass()->getName()] = $clsRes;
        }

        foreach ($resources as $resource) {
            /** @var DaemonThread $daemon */
            $daemon = $resource->getAttribute(DaemonThread::class);
            if ($daemon->coreSize <= 0) {
                continue;
            }
            for ($i = 0; $i < $daemon->coreSize; $i++) {
                $ps = $resource->getClass()->newInstance($wServer, $this->applicationContext, $i);
                self::logInfo('Starting DaemonThread ' . $ps::class);
                $wServer->addProcess($ps);
            }
        }
    }
}