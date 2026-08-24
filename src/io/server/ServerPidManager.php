<?php
declare(strict_types=1);

namespace dev\winterframework\io\server;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\shm\ShmTable;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Process;

class ServerPidManager {
    use Wlf4p;

    private ShmTable $pidTable;
    // Singleton instance for static callbacks
    protected static ?self $instance = null;
    protected static bool $processSignalsRegistered = false;

    public function __construct(
        protected ApplicationContext $ctx
    ) {
        $this->pidTable = new ShmTable(
            1024,
            [
                ['pid', ShmTable::TYPE_INT],
                ['type', ShmTable::TYPE_INT]
            ]
        );
        self::$instance = $this;
    }

    public function getPidTable(): ShmTable {
        return $this->pidTable;
    }

    public function addPid(string $id, int $pid, int $psType): void {
        $this->pidTable[$id] = ['pid' => $pid, 'type' => $psType];
    }

    /** Gracefully kill all managed processes */
    public function killAll(bool $killSelf = true): void {
        $myPid = getmypid();
        $masterPid = 0;
        if (isset($this->pidTable['master'])) {
            $masterPid = intval($this->pidTable['master']['pid']);
            if ($masterPid > 0) {
                Process::kill($masterPid, SIGTERM);
            }
        }
        if (isset($this->pidTable['manager'])) {
            $pid = intval($this->pidTable['manager']['pid']);
            if ($pid > 0 && $pid != $myPid) {
                self::logInfo("Stopping Manager ($pid)");
                Process::kill($pid, SIGKILL);
            }
        }
        foreach ($this->pidTable as $id => $data) {
            $pid = intval($data['pid']);
            if ($myPid != $pid && $id != 'master' && $pid > 0) {
                self::logInfo("Stopping worker ($id, $pid)");
                Process::kill($pid, SIGTERM);
            }
        }
        usleep(500000);
        foreach ($this->pidTable as $id => $data) {
            $pid = intval($data['pid']);
            if ($myPid != $pid && $id != 'master' && $pid > 0) {
                self::logInfo("Stopping worker ($id, $pid)");
                @Process::kill($pid, SIGKILL);
            }
            unset($this->pidTable[$id]);
        }
        if ($masterPid > 0) {
            self::logInfo("Stopping MASTER ($masterPid)");
            @Process::kill($masterPid, SIGKILL);
            if (function_exists('posix_kill')) {
                @posix_kill(-$masterPid, SIGKILL);
            }
        }
        if ($killSelf) {
            self::logInfo("Stopping self ($myPid)");
            if (function_exists('posix_kill')) {
                @posix_kill($myPid, SIGKILL);
            }
            exit(1);
        }
    }

    // Static signal handling
    public static function onProcessSignal(int $signal): void {
        if (self::$instance !== null) {
            self::$instance->killAll();
        }
        exit(0);
    }

    public static function registerProcessSignals(): void {
        if (self::$processSignalsRegistered) {
            return;
        }
        // Only register SIGINT (Ctrl+C). SIGTERM is already owned by Swoole internally.
        if (class_exists('Swoole\\Process')) {
            Process::signal(SIGINT, fn(int $s) => self::onProcessSignal($s));
        }
        self::$processSignalsRegistered = true;
    }
}