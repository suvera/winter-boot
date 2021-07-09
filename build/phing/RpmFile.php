<?php
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

class RpmFile {

    protected string $localFile;
    protected string $installDir;
    protected string $fileName = '';

    protected string $fileMode = '755';
    protected string $userName = '';
    protected string $groupName = '';

    public function getLocalFile(): string {
        return $this->localFile;
    }

    public function setLocalFile(string $localFile): void {
        $this->localFile = $localFile;
    }

    public function getInstallDir(): string {
        return $this->installDir;
    }

    public function setInstallDir(string $installDir): void {
        $this->installDir = $installDir;
    }

    public function getFileName(): string {
        if (!$this->fileName) {
            $this->fileName = basename($this->localFile);
        }
        return $this->fileName;
    }

    public function setFileName(string $fileName): void {
        $this->fileName = $fileName;
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

}