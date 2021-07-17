<?php
declare(strict_types=1);

namespace dev\winterframework\io\server;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Process;
use Swoole\Table;

class ServerPidManager {
    use Wlf4p;

    private Table $pidTable;

    public function __construct(
        protected ApplicationContext $ctx
    ) {
        $table = new Table(1024);
        $table->column('pid', Table::TYPE_INT);
        $table->column('type', Table::TYPE_INT);
        $table->create();

        $this->pidTable = $table;
    }

    public function getPidTable(): Table {
        return $this->pidTable;
    }

    public function addPid(string $id, int $pid, int $psType): void {
        $this->pidTable[$id] = ['pid' => $pid, 'type' => $psType];
    }

    public function killAll(bool $killSelf = true): void {
        $myPid = getmypid();

        $masterPid = 0;
        if (isset($this->pidTable['master'])) {
            $masterPid = $this->pidTable['master']['pid'];
            exec('kill -2 -' . $masterPid);
        }

        if (isset($this->pidTable['manager'])) {
            $pid = $this->pidTable['manager']['pid'];
            self::logInfo("Stopping Manager ($pid)");
            Process::kill($pid, SIGKILL);
        }
        foreach ($this->pidTable as $id => $data) {
            $pid = intval($data['pid']);
            if ($myPid != $pid && $id != 'master') {
                self::logInfo("Stopping worker ($id, $pid)");
                Process::kill($pid, SIGTERM);
            }
        }
        sleep(1);
        foreach ($this->pidTable as $id => $data) {
            $pid = intval($data['pid']);
            if ($myPid != $pid && $id != 'master') {
                self::logInfo("Stopping worker ($id, $pid)");
                Process::kill($pid, SIGKILL);
            }
            unset($this->pidTable[$id]);
        }

        if ($masterPid > 0) {
            self::logInfo("Stopping MASTER ($masterPid)");
            Process::kill($masterPid, SIGKILL);
        }

        if ($killSelf) {
            self::logInfo("Stopping self ($myPid)");
            Process::kill($myPid, SIGTERM);
            exit;
        }
    }
}