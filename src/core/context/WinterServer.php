<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\io\server\ServerPidManager;
use dev\winterframework\io\server\WinterServerAdmin;
use dev\winterframework\util\Debug;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\SocketUtil;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\HTTP\Server;
use Swoole\Process;
use Swoole\Server\Port;
use Throwable;

class WinterServer {
    use Wlf4p;

    protected Server $server;

    protected array $serverArgs = [];

    protected static array $defaultServerArgs = [
        'reload_async' => false,
        'max_wait_time' => 3, // seconds
        /**
         * A worker process is restarted to avoid memory leak when receiving
         *      max_request + rand(0, max_request_grace) requests.
         * max_request is 0 which means there is no limit of the max requests.
         */
        'max_request' => 0,
    ];

    /**
     * @var callable[][]
     */
    protected array $eventCallbacks = [];

    /**
     * @var WinterTable[]
     */
    protected array $scheduledTables = [];
    private mixed $address;
    private mixed $port;
    protected static bool $processSignalsRegistered = false;
    protected WinterServerAdmin $adminHandler;
    protected ServerPidManager $pidManager;

    public function __construct(
        private ApplicationContext $appCtx,
        private ApplicationContextData $appCtxData
    ) {

        $prop = $this->appCtxData->getPropertyContext();
        $this->address = $prop->get('server.address', '127.0.0.1');
        $this->port = $prop->get('server.port', '8080');

        $this->server = new Server($this->address, $this->port);
        $this->pidManager = new ServerPidManager($this->appCtx);
        $this->adminHandler = new WinterServerAdmin($this->appCtx, $this->pidManager);
    }

    public function addPid(string $id, int $pid, int $psType): void {
        $this->pidManager->addPid($id, $pid, $psType);
    }

    public function getPidManager(): ServerPidManager {
        return $this->pidManager;
    }

    public function getAppCtx(): ApplicationContext {
        return $this->appCtx;
    }

    public function getAppCtxData(): ApplicationContextData {
        return $this->appCtxData;
    }

    public function getServer(): Server {
        return $this->server;
    }

    public function setServer(Server $server): WinterServer {
        $this->server = $server;
        return $this;
    }

    public function isServerStarted(): bool {
        return SocketUtil::isPortOpened($this->address, $this->port, 2);
    }

    public function getServerArgs(): array {
        return $this->serverArgs;
    }

    public function setServerArgs(array $serverArgs): WinterServer {
        $this->serverArgs = $serverArgs;
        return $this;
    }

    public function addServerArg(string $name, mixed $value): WinterServer {
        $this->serverArgs[$name] = $value;
        return $this;
    }

    public function getServerArg(string $name): mixed {
        return $this->serverArgs[$name] ?? null;
    }

    public function getScheduledTables(): array {
        return $this->scheduledTables;
    }

    public function addScheduledTable(int $workerId, WinterTable $table): WinterServer {
        $this->scheduledTables[$workerId] = $table;
        return $this;
    }

    public function getScheduledTable(int $workerId): ?WinterTable {
        return $this->scheduledTables[$workerId] ?? null;
    }

    public function addEventCallback(string $eventName, callable $callback): void {
        if (!isset($this->eventCallbacks[$eventName])) {
            $this->eventCallbacks[$eventName] = [];
        }
        $this->eventCallbacks[$eventName][] = $callback;
    }

    protected function registerEventCallbacks(): void {
        foreach ($this->eventCallbacks as $eventName => $callbacks) {
            $this->server->on($eventName, function (...$args) use ($callbacks) {
                foreach ($callbacks as $callback) {
                    $callback(...$args);
                }
            });
        }
    }

    public function addListener(string $host, int|string $port, string|int $socket_type): bool|Port {
        return $this->server->addlistener($host, $port, $socket_type);
    }

    public function addProcess(Process $process): bool {
        $val = $this->server->addProcess($process);
        return boolval($val);
    }

    public function start(): void {
        self::logInfo("Starting Http server on $this->address:$this->port" . ', pid:' . getmypid());

        $this->registerEventCallbacks();
        $this->beginAdmin();
        $this->serverArgs = array_merge(self::$defaultServerArgs, $this->serverArgs);
        $this->server->set($this->getServerArgs());
        $this->server->start();
    }

    protected function beginAdmin(): void {
        $port = $this->appCtx->getPropertyInt('server.admin.port', 0);
        if (!$port) {
            return;
        }
        /** @var Port $servPort */
        $servPort = $this->server->addlistener('127.0.0.1', $port, SWOOLE_SOCK_TCP);

        $servPort->on('Request', function (Request $request, Response $response) {
            $this->adminHandler->serveRequest($request, $response);
        });
    }

    public static function onProcessSignal(int $signal): void {
        echo "Got Signal: $signal\n";
        self::logInfo("Got Signal: $signal");
        die;
    }

    public static function registerProcessSignals(): void {
    }

    public function shutdown(string $message = null, Throwable $ex = null): void {
        $msg = 'Shutting down the server ';
        if ($message) {
            $msg .= "$message\n";
        }
        if ($ex) {
            $msg .= $ex->getMessage() . "\n";
            $msg .= Debug::exceptionBacktrace($ex);
        }

        self::logError($msg);
        $this->pidManager->killAll();
    }

}