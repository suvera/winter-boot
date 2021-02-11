<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\util\log\Wlf4p;
use SplFileInfo;
use SplFileObject;
use Throwable;

class LocalLock implements Lock {
    use Wlf4p;

    private SplFileInfo $fileInfo;
    private ?SplFileObject $fileObj = null;

    public function __construct(
        private string $name
    ) {
        $this->fileInfo = new SplFileInfo(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . hash('sha256', $name) . '.lock'
        );
    }

    public function tryLock(): bool {
        $my_pid = getmypid();
        $fileObj = null;

        if ($this->fileInfo->isFile()) {
            /** @noinspection PhpRedundantOptionalArgumentInspection */
            $fileObj = $this->fileInfo->openFile('r');
            if ($fileObj) {
                $line = $fileObj->fgets();
                list($pid, $time) = explode(':', $line);

                if ($my_pid == $pid) {
                    return true;
                }

                if (file_exists("/proc/$pid")) {
                    throw new LockException('Could not acquire lock as other PID '
                        . $pid
                        . ' already acquired and updated at '
                        . $time
                        . ' for lock ' . $this->getName());
                } else {
                    unlink($this->fileInfo->getRealPath());
                }
            } else {
                throw new LockException('Could not open lock file for reading '
                    . $this->fileInfo->getRealPath());
            }
        }

        try {
            $fileObj = $this->fileInfo->openFile('x');
        } catch (Throwable $e) {
            throw new LockException('Could acquire Lock ' . $this->name, 0, $e);
        }
        if (!$fileObj) {
            return false;
        }

        $locked = $fileObj->flock(LOCK_EX | LOCK_NB);

        if ($locked) {
            $fileObj->fwrite($my_pid . ':' . time());
            $this->fileObj = $fileObj;
        }

        return $locked;
    }

    public function isLocked(): bool {
        return ($this->fileObj != null) && ($this->fileInfo->isFile());
    }

    public function unlock(): void {
        if ($this->fileObj) {
            $this->fileObj->flock(LOCK_UN);
            unlink($this->fileInfo->getRealPath());
            $this->fileObj = null;
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function isDistributed(): bool {
        return false;
    }

}