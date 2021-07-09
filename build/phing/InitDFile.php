<?php
declare(strict_types=1);

use Phing\Exception\BuildException;
use Phing\Io\File;

class InitDFile {

    private File $destFile;
    private string $serviceName;
    private string $appBinary;
    protected string $installDir = '/etc/init.d';
    private string $configDir = '';
    private string $adminPort = '';
    private string $adminTokenFile = '';
    private string $logFile = '';
    private string $pidFile = '';
    private string $username = 'root';
    private string $phpBinary = '/usr/bin/php';
    private ?File $initDTpl = null;
    protected string $fileMode = '644';
    protected string $userName = 'root';
    protected string $groupName = 'root';

    /**
     * @throws
     */
    public function build() {
        if (!$this->initDTpl) {
            $this->initDTpl = new File(dirname(__DIR__) . '/init.d.sample.sh');
        }

        if (!$this->initDTpl->exists()) {
            throw new BuildException(
                'init.d Template does not exist: ' . $this->initDTpl->getAbsolutePath()
            );
        }

        if (!$this->logFile) {
            $this->logFile = '/var/log/' . $this->serviceName . '.log';
        }

        if (!$this->pidFile) {
            $this->pidFile = '/var/run/' . $this->serviceName . '.pid';
        }

        $this->createInitScript();
    }

    public function getVariables(): array {
        return [
            '<<serviceName>>' => $this->serviceName,
            '<<username>>' => $this->username,
            '<<appBinary>>' => $this->appBinary,
            '<<configDir>>' => $this->configDir,
            '<<adminPort>>' => $this->adminPort,
            '<<adminTokenFile>>' => $this->adminTokenFile,
            '<<logFile>>' => $this->logFile,
            '<<pidFile>>' => $this->pidFile,
            '<<phpBinary>>' => $this->phpBinary
        ];
    }

    /**
     * @throws
     */
    protected function createInitScript(): void {
        $row = $this->getVariables();

        $destFile = $this->destFile->getAbsolutePath();

        $contents = $this->initDTpl->contents();

        $contents = str_replace(array_keys($row), array_values($row), $contents);

        file_put_contents($destFile, $contents);
    }

    public function getServiceName(): string {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): void {
        $this->serviceName = $serviceName;
    }

    public function getAppBinary(): string {
        return $this->appBinary;
    }

    public function setAppBinary(string $appBinary): void {
        $this->appBinary = $appBinary;
    }

    public function getConfigDir(): string {
        return $this->configDir;
    }

    public function setConfigDir(string $configDir): void {
        $this->configDir = $configDir;
    }

    public function getAdminPort(): string {
        return $this->adminPort;
    }

    public function setAdminPort(string $adminPort): void {
        $this->adminPort = $adminPort;
    }

    public function getAdminTokenFile(): string {
        return $this->adminTokenFile;
    }

    public function setAdminTokenFile(string $adminTokenFile): void {
        $this->adminTokenFile = $adminTokenFile;
    }

    public function getLogFile(): string {
        return $this->logFile;
    }

    public function setLogFile(string $logFile): void {
        $this->logFile = $logFile;
    }

    public function getPidFile(): string {
        return $this->pidFile;
    }

    public function setPidFile(string $pidFile): void {
        $this->pidFile = $pidFile;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function setUsername(string $username): void {
        $this->username = $username;
    }

    public function getPhpBinary(): string {
        return $this->phpBinary;
    }

    public function setPhpBinary(string $phpBinary): void {
        $this->phpBinary = $phpBinary;
    }

    public function getInitDTpl(): ?File {
        return $this->initDTpl;
    }

    public function setInitDTpl(File $initDTpl): void {
        $this->initDTpl = $initDTpl;
    }

    public function getDestFile(): File {
        return $this->destFile;
    }

    public function setDestFile(File $destFile): void {
        $this->destFile = $destFile;
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

    public function getGroupName(): string {
        return $this->groupName;
    }

    public function setGroupName(string $groupName): void {
        $this->groupName = $groupName;
    }

}
