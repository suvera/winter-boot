<?php
/** @noinspection SpellCheckingInspection */
/** @noinspection PhpUnused */
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

use Phing\Exception\BuildException;
use Phing\Io\File;
use Phing\Project;
use Phing\Task;

require_once(__DIR__ . '/RpmFile.php');
require_once(__DIR__ . '/RpmDirectory.php');
require_once(__DIR__ . '/RpmScriptlet.php');
require_once(__DIR__ . '/RpmDefine.php');
require_once(__DIR__ . '/RmdirTask.php');
require_once(__DIR__ . '/InitDFile.php');
require_once(__DIR__ . '/WinterPharTask.php');

class RpmBuildTask extends Task {

    /**
     * @var RpmFile[]
     */
    protected array $files = [];
    /**
     * @var RpmDirectory[]
     */
    protected array $folders = [];
    /**
     * @var RpmScriptlet[]
     */
    protected array $scriptlets = [];

    /**
     * @var RpmDefine[]
     */
    protected array $defines = [];

    protected string $name;
    protected string $version;
    protected string $release = '';
    protected string $group = '';
    protected string $distribution = '';
    protected string $license = '';
    protected string $defaultDirmode = '755';
    protected string $defaultFilemode = '644';
    protected string $defaultUsername = '';
    protected string $defaultGroupname = '';
    protected string $url = '';
    protected string $summary = '';
    protected ?File $topDir = null;
    protected File $specFile;

    protected string $topDirPath;
    protected string $rpmSpecFilePath;
    protected string $buildRootPath;
    /**
     * @var InitDFile[]
     */
    protected array $initDFiles = [];

    /**
     * @throws
     */
    public function main() {
        $this->preFlight();

        $this->buildSpecFile();

        $cmd = [
            'rpmbuild',
            '-bb',
            '--define', '"_topdir ' . $this->topDirPath . '"',
            $this->specFile->getAbsolutePath()
        ];

        //print_r($this->files);
        $this->processFiles();

        $cmdStr = implode(" ", $cmd);
        $this->log(
            'running rpmbuild command: ' . $cmdStr
        );
        exec($cmdStr, $output, $status);

        if ($status) {
            $this->log(
                'rpmbuild command failed: ' . implode("\n", $output),
                Project::MSG_ERR
            );
            throw new BuildException('rpmbuild command failed: ' . implode("\n", $output));
        }
    }

    protected function processFiles(): void {
        foreach ($this->initDFiles as $initDFile) {
            $initDFile->build();
        }
    }

    /**
     * @throws
     */
    protected function preFlight(): void {
        if (!$this->topDir) {
            $this->topDir = new File('target/rpm');
        }

        if (!$this->topDir->isDirectory()) {
            throw new BuildException('topDir directory does not exist.' . $this->topDir->getAbsolutePath());
        }
        $this->topDirPath = $this->topDir->getAbsolutePath();
        $this->rpmSpecFilePath = $this->topDirPath . DIRECTORY_SEPARATOR . 'rpm.spec';

        $define = new RpmDefine();
        $define->setStatement('winterTopDir ' . $this->topDirPath);
        $this->defines[] = $define;

        $this->specFile = new File($this->rpmSpecFilePath);
        $this->specFile->createNewFile();

        if (!file_exists($this->topDirPath . '/SPECS')) {
            mkdir($this->topDirPath . '/SPECS', 755);
        }
        if (!file_exists($this->topDirPath . '/RPMS')) {
            mkdir($this->topDirPath . '/RPMS', 755);
        }

        if (!file_exists($this->topDirPath . '/BUILD')) {
            mkdir($this->topDirPath . '/BUILD', 755);
        }
        if (!file_exists($this->topDirPath . '/SRPMS')) {
            mkdir($this->topDirPath . '/SRPMS', 755);
        }
        if (!file_exists($this->topDirPath . '/SOURCES')) {
            mkdir($this->topDirPath . '/SOURCES', 755);
        }

        $this->buildRootPath = $this->topDirPath . DIRECTORY_SEPARATOR . 'BUILDROOT';
        if (!file_exists($this->buildRootPath)) {
            mkdir($this->buildRootPath, 755);
        }
    }

    /**
     * @throws
     */
    protected function buildSpecFile(): void {
        $fh = fopen($this->specFile->getAbsolutePath(), 'w');

        fwrite($fh, 'Name: ' . $this->getName() . PHP_EOL);
        fwrite($fh, 'Version: ' . $this->getVersion() . PHP_EOL);
        fwrite($fh, 'Release: ' . $this->getRelease() . PHP_EOL);

        foreach ($this->defines as $define) {
            fwrite($fh, '%define ' . $define->getStatement() . PHP_EOL);
        }

        fwrite($fh, PHP_EOL);

        fwrite($fh, 'Summary: ' . $this->getSummary() . PHP_EOL);
        fwrite($fh, 'Group: ' . $this->getGroup() . PHP_EOL);
        fwrite($fh, 'License: ' . $this->getLicense() . PHP_EOL);
        fwrite($fh, 'URL: ' . $this->getUrl() . PHP_EOL);

        fwrite($fh, PHP_EOL);

        //fwrite($fh, 'BuildRequires: ' . PHP_EOL);
        fwrite($fh, 'BuildRoot: ' . $this->getTopDir()->getAbsolutePath());

        fwrite($fh, PHP_EOL);

        fwrite($fh, '%description' . PHP_EOL);
        fwrite($fh, $this->getSummary() . PHP_EOL);
        fwrite($fh, PHP_EOL);

        fwrite($fh, '%prep' . PHP_EOL);
        fwrite($fh, PHP_EOL);

        fwrite($fh, '%build' . PHP_EOL);
        fwrite($fh, PHP_EOL);

        fwrite($fh, '%install' . PHP_EOL);
        //fwrite($fh, 'cd %{winterTopDir}' . PHP_EOL);
        fwrite($fh, 'echo $RPM_BUILD_ROOT' . PHP_EOL);

        $baseDir = $this->getProject()->getBasedir()->getAbsolutePath();
        foreach ($this->folders as $folder) {
            $dir = dirname($folder->getInstallDir());
            $dir = trim($dir);
            if ($dir[0] != '/') {
                $dir = '/' . $dir;
            }

            $src = trim($folder->getLocalDir());
            $src = rtrim($src, '/');
            if ($src[0] != '/') {
                $src = $baseDir . '/' . $src;
            }

            fwrite($fh, 'mkdir -p $RPM_BUILD_ROOT' . $dir . PHP_EOL);
            fwrite($fh, 'cp -R ' . $src . ' $RPM_BUILD_ROOT' . $dir . PHP_EOL);
        }

        foreach ($this->getFiles() as $file) {
            $dir = $file->getInstallDir();
            $dir = trim($dir);
            $dir = rtrim($dir, '/');
            if ($dir[0] != '/') {
                $dir = '/' . $dir;
            }
            $destFile = $dir . '/' . $file->getFileName();

            $srcFile = $file->getLocalFile();
            $srcFile = trim($srcFile);
            if ($srcFile[0] != '/') {
                $srcFile = $baseDir . '/' . $srcFile;
            }

            fwrite($fh, 'mkdir -p $RPM_BUILD_ROOT' . $dir . PHP_EOL);
            fwrite($fh, 'cp ' . $srcFile . ' $RPM_BUILD_ROOT' . $destFile . PHP_EOL);
        }
        foreach ($this->initDFiles as $initDFile) {
            $srcFile = $initDFile->getDestFile()->getAbsolutePath();

            $dir = $initDFile->getInstallDir();
            $dir = trim($dir);
            $dir = rtrim($dir, '/');
            if ($dir[0] != '/') {
                $dir = '/' . $dir;
            }
            $installFile = $dir . '/' . basename($srcFile);

            fwrite($fh, 'mkdir -p $RPM_BUILD_ROOT' . $dir . PHP_EOL);
            fwrite($fh, 'cp ' . $srcFile . ' $RPM_BUILD_ROOT' . $installFile . PHP_EOL);
        }
        fwrite($fh, PHP_EOL);

        fwrite($fh, '%clean' . PHP_EOL);
        fwrite($fh, PHP_EOL);

        fwrite($fh, '%files -n %name' . PHP_EOL);
        foreach ($this->folders as $folder) {
            fwrite($fh, '%defattr(' . $folder->getDirMode() . ',' . $folder->getUserName() . ','
                . $folder->getGroupName() . ')' . PHP_EOL);
            fwrite($fh, '' . $folder->getInstallDir() . PHP_EOL);
        }
        foreach ($this->getFiles() as $file) {
            fwrite($fh, '%defattr(' . $file->getFileMode() . ',' . $file->getUserName() . ','
                . $file->getGroupName() . ')' . PHP_EOL);
            fwrite($fh, '' . $file->getInstallDir() . '/' . $file->getFileName() . PHP_EOL);
        }
        foreach ($this->initDFiles as $initDFile) {
            $srcFile = $initDFile->getDestFile()->getAbsolutePath();

            $dir = $initDFile->getInstallDir();
            $dir = trim($dir);
            $dir = rtrim($dir, '/');
            if ($dir[0] != '/') {
                $dir = '/' . $dir;
            }
            $installFile = $dir . '/' . basename($srcFile);

            fwrite($fh, '%defattr(' . $initDFile->getFileMode() . ',' . $initDFile->getUserName() . ','
                . $file->getGroupName() . ')' . PHP_EOL);
            fwrite($fh, '' . $installFile . PHP_EOL);
        }
        fwrite($fh, PHP_EOL);

        $preScripts = [];
        $postScripts = [];
        $preUnScripts = [];
        $postUnScripts = [];

        foreach ($this->scriptlets as $scriptlet) {
            switch ($scriptlet->getOnEvent()) {
                case 'preinstall':
                    $preScripts[] = $scriptlet;
                    break;

                case 'postinstall':
                    $postScripts[] = $scriptlet;
                    break;

                case 'preremove':
                    $preUnScripts[] = $scriptlet;
                    break;

                case 'postremove':
                    $postUnScripts[] = $scriptlet;
                    break;
            }
        }

        $this->writeScriptlet($fh, $preScripts, '%pre');
        $this->writeScriptlet($fh, $postScripts, '%post');
        $this->writeScriptlet($fh, $preUnScripts, '%preun');
        $this->writeScriptlet($fh, $postUnScripts, '%postun');

        fflush($fh);
        fclose($fh);
    }

    /**
     * @param resource $fh
     * @param RpmScriptlet[] $scriptlets
     * @param string $def
     */
    protected function writeScriptlet(mixed $fh, array $scriptlets, string $def): void {
        if (!$scriptlets) {
            return;
        }
        fwrite($fh, $def . PHP_EOL);
        foreach ($scriptlets as $scriptlet) {
            $c = file_get_contents($scriptlet->getFile()->getAbsolutePath());
            fwrite($fh, $c . PHP_EOL);
            fwrite($fh, PHP_EOL);
        }
    }

    public function createRpmFile(): RpmFile {
        $obj = new RpmFile();
        $obj->setGroupName($this->defaultGroupname);
        $obj->setUserName($this->defaultUsername);
        $obj->setFileMode($this->defaultFilemode);
        $this->files[] = $obj;
        return $obj;
    }

    public function createRpmDirectory(): RpmDirectory {
        $obj = new RpmDirectory();

        $obj->setGroupName($this->defaultGroupname);
        $obj->setUserName($this->defaultUsername);
        $obj->setDirMode($this->defaultDirmode);
        $obj->setFileMode($this->defaultFilemode);

        $this->folders[] = $obj;
        return $obj;
    }

    public function createRpmScriptlet(): RpmScriptlet {
        $obj = new RpmScriptlet();
        $this->scriptlets[] = $obj;
        return $obj;
    }

    public function createRpmDefine(): RpmDefine {
        $obj = new RpmDefine();
        $this->defines[] = $obj;
        return $obj;
    }

    public function createInitDFile(): InitDFile {
        $obj = new InitDFile();
        $this->initDFiles[] = $obj;

        if ($this->defaultGroupname) {
            $obj->setGroupName($this->defaultGroupname);
        }
        if ($this->defaultUsername) {
            $obj->setUserName($this->defaultUsername);
        }
        if ($this->defaultFilemode) {
            $obj->setFileMode($this->defaultFilemode);
        }

        return $obj;
    }

    public function getFiles(): array {
        return $this->files;
    }

    public function setFiles(array $files): void {
        $this->files = $files;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getVersion(): string {
        return $this->version;
    }

    public function setVersion(string $version): void {
        $this->version = $version;
    }

    public function getRelease(): string {
        return $this->release;
    }

    public function setRelease(string $release): void {
        $this->release = $release;
    }

    public function getGroup(): string {
        return $this->group;
    }

    public function setGroup(string $group): void {
        $this->group = $group;
    }

    public function getDistribution(): string {
        return $this->distribution;
    }

    public function setDistribution(string $distribution): void {
        $this->distribution = $distribution;
    }

    public function getLicense(): string {
        return $this->license;
    }

    public function setLicense(string $license): void {
        $this->license = $license;
    }

    public function getDefaultDirmode(): string {
        return $this->defaultDirmode;
    }

    public function setDefaultDirmode(string $defaultDirmode): void {
        $this->defaultDirmode = $defaultDirmode;
    }

    public function getDefaultFilemode(): string {
        return $this->defaultFilemode;
    }

    public function setDefaultFilemode(string $defaultFilemode): void {
        $this->defaultFilemode = $defaultFilemode;
    }

    public function getDefaultUsername(): string {
        return $this->defaultUsername;
    }

    public function setDefaultUsername(string $defaultUsername): void {
        $this->defaultUsername = $defaultUsername;
    }

    public function getDefaultGroupname(): string {
        return $this->defaultGroupname;
    }

    public function setDefaultGroupname(string $defaultGroupname): void {
        $this->defaultGroupname = $defaultGroupname;
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function setUrl(string $url): void {
        $this->url = $url;
    }

    public function getSummary(): string {
        return $this->summary;
    }

    public function setSummary(string $summary): void {
        $this->summary = $summary;
    }

    public function getTopDir(): ?File {
        return $this->topDir;
    }

    public function setTopDir(File $topDir): void {
        $this->topDir = $topDir;
    }

}