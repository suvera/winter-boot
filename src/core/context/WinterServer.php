<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use Swoole\Server;

class WinterServer {

    protected Server $server;

    protected array $serverArgs = [];

    /**
     * @var WinterTable[]
     */
    protected array $asyncTables = [];

    /**
     * @var WinterTable[]
     */
    protected array $scheduledTables = [];

    public function getServer(): Server {
        return $this->server;
    }

    public function setServer(Server $server): WinterServer {
        $this->server = $server;
        return $this;
    }

    public function getServerArgs(): array {
        return $this->serverArgs;
    }

    public function setServerArgs(array $serverArgs): WinterServer {
        $this->serverArgs = $serverArgs;
        return $this;
    }

    public function getAsyncTables(): array {
        return $this->asyncTables;
    }

    public function addAsyncTable(int $workerId, WinterTable $table): WinterServer {
        $this->asyncTables[$workerId] = $table;
        return $this;
    }

    public function getAsyncTable(int $workerId): ?WinterTable {
        return $this->asyncTables[$workerId] ?? null;
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

}