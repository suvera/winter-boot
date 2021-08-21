<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

class ProcessUtil {
    const STDIN = 0;
    const STDOUT = 1;
    const STDERR = 2;

    private static array $typeNames = [
        ProcessType::OTHER => 'Other',
        ProcessType::MASTER => 'Master',
        ProcessType::MANAGER => 'ProcessManager',
        ProcessType::HTTP_WORKER => 'HttpWorker',
        ProcessType::TASK_WORKER => 'TaskWorker',
        ProcessType::ASYNC_WORKER => 'AsyncWorker',
        ProcessType::SCHED_WORKER => 'SchedulingWorker',
        ProcessType::KV_MONITOR => 'KvMonitor',
        ProcessType::QUEUE_MONITOR => 'QueueMonitor',
        ProcessType::KV_SERVER => 'KvServer',
        ProcessType::QUEUE_SERVER => 'QueueServer',
    ];

    public static function getProcessTypeName(int $type): string {
        return self::$typeNames[$type] ?? 'Other';
    }

    public static function getPidInfo(int $pid): ?PidInfo {
        $file = '/proc/' . $pid . '/status';
        if (!file_exists($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $pi = new PidInfo($pid);
        $time = filectime($file);
        $pi->setRunningSince(intval($time));

        foreach ($lines as $line) {
            $line = trim($line, '\0');
            $parts = explode(':', $line, 2);
            $key = trim($parts[0], '\0');
            $value = $parts[1] ?? '';

            $value = str_replace(["\n", "\r", "\t"], '', $value);
            $value = trim($value, '\0');
            switch ($key) {
                case 'Name':
                    $pi->setName($value);
                    break;

                case 'State':
                    $pi->setState($value);
                    break;

                case 'PPid':
                    $pi->setParentPid(intval($value));
                    break;

                case 'FDSize':
                    $pi->setFdSize(intval($value));
                    break;

                case 'VmPeak':
                    $val = intval($value);
                    $pi->setVirtualMemoryPeak($val * 1024);
                    break;

                case 'VmSize':
                    $val = intval($value);
                    $pi->setVirtualMemorySize($val * 1024);
                    break;

                case 'VmRSS':
                    $val = intval($value);
                    $pi->setVirtualMemoryRss($val * 1024);
                    break;

                case 'Threads':
                    $pi->setThreads(intval($value));
                    break;
            }
        }

        return $pi;
    }

    public static function getChildPids(int|string $pid) : array {
        $pid = intval($pid);
        if ($pid <= 0) {
            return [];
        }

        $path = "/proc/$pid/task/$pid/children";
        if (!file_exists($path)) {
            return [];
        }

        $lines = file($path);
        if ($lines === false) {
            return [];
        }

        $list = [];
        foreach ($lines as $line) {
            $line = trim($line, '\0');
            $pids = preg_split('/\s+/', $line);
            foreach ($pids as $pd) {
                $pd = intval($pd);
                if ($pd > 0) {
                    $list[] = $pd;
                }
            }
        }

        foreach (array_values($list) as $pd) {
            $pids = self::getChildPids($pd);
            if ($pids) {
                $list = array_merge($list, $pids);
            }
        }

        return $list;
    }
}