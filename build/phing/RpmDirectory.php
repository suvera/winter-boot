<?php
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

class RpmDirectory {

    protected string $localDir;
    protected string $installDir;

    protected string $dirMode = '755';
    protected string $fileMode = '644';
    protected string $userName = '';
    protected string $groupName = '';

    public function getLocalDir(): string {
        return $this->localDir;
    }

    public function setLocalDir(string $localDir): void {
        $this->localDir = $localDir;
    }

    public function getInstallDir(): string {
        return $this->installDir;
    }

    public function setInstallDir(string $installDir): void {
        $this->installDir = $installDir;
    }

    public function getFileMode(): string {
        return $this->fileMode;
    }

    public function setFileMode(string $fileMode): void {
        $this->fileMode = $fileMode;
    }

    public function getUserName(): string {
        return $this->userName;
    }

    public function setUserName(string $userName): void {
        $this->userName = $userName;
    }

    public function getGroupName(): string {
        return $this->groupName;
    }

    public function setGroupName(string $groupName): void {
        $this->groupName = $groupName;
    }

    public function getDirMode(): string {
        return $this->dirMode;
    }

    public function setDirMode(string $dirMode): void {
        $this->dirMode = $dirMode;
    }

}