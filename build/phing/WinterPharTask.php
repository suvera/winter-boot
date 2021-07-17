<?php
declare(strict_types=1);

use Phing\Exception\BuildException;
use Phing\Io\File;
use Phing\Project;
use Phing\Task\System\MatchingTask;
use Phing\Task\System\PharMetadata;
use Phing\Type\FileSet;

require_once(__DIR__ . '/CliStub.php');

class WinterPharTask extends MatchingTask {

    /**
     * @var CliStub[]
     */
    protected array $cliStubs = [];
    protected string $winterDir;
    protected string $buildBaseDir;

    protected string $name;
    protected string $version;
    protected string $release = '';
    protected string $summary = '';
    protected string $outFileProperty = 'phar.Filename';

    protected File $topDir;
    protected int $compression = Phar::NONE;
    protected File $baseDirectory;
    protected File $key;
    protected string $keyPassword = '';
    protected int $signatureAlgorithm = Phar::SHA1;
    protected array $filesets = [];
    protected PharMetadata $metadata;
    protected string $alias;

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

    public function getSummary(): string {
        return $this->summary;
    }

    public function setSummary(string $summary): void {
        $this->summary = $summary;
    }

    public function getOutFileProperty(): string {
        return $this->outFileProperty;
    }

    public function setOutFileProperty(string $outFileProperty): void {
        $this->outFileProperty = $outFileProperty;
    }

    /**
     * @throws
     */
    public function main(): void {
        if (empty($this->cliStubs)) {
            throw new BuildException('There are no STUBs setup for phar package.');
        }

        $this->winterDir = dirname(dirname(__DIR__));
        $this->buildBaseDir = realpath($this->baseDirectory->getPath());
        $this->buildBaseDir = rtrim($this->buildBaseDir, '/');

        $stub = new CliStub();
        $stub->setName('kv-server');
        $stub->setScriptPath(new File($this->winterDir . '/bin/kv-server.php'));
        $this->cliStubs[] = $stub;

        $stub = new CliStub();
        $stub->setName('queue-server');
        $stub->setScriptPath(new File($this->winterDir . '/bin/queue-server.php'));
        $this->cliStubs[] = $stub;

        $this->build();
    }

    public function createStub(): CliStub {
        $obj = new CliStub();
        $this->cliStubs[] = $obj;
        return $obj;
    }

    public function createMetadata(): PharMetadata {
        return $this->metadata = new PharMetadata();
    }

    public function createFileSet(): FileSet {
        $this->fileset = new FileSet();
        $this->filesets[] = $this->fileset;

        return $this->fileset;
    }

    public function setSignature($algorithm): string {
        switch ($algorithm) {
            case 'md5':
                $this->signatureAlgorithm = Phar::MD5;

                break;

            case 'sha1':
                $this->signatureAlgorithm = Phar::SHA1;

                break;

            case 'sha256':
                $this->signatureAlgorithm = Phar::SHA256;

                break;

            case 'sha512':
                $this->signatureAlgorithm = Phar::SHA512;

                break;

            case 'openssl':
                $this->signatureAlgorithm = Phar::OPENSSL;

                break;

            default:
                break;
        }
        return '';
    }

    public function setCompression($compression): string {
        switch ($compression) {
            case 'gzip':
                $this->compression = Phar::GZ;

                break;

            case 'bzip2':
                $this->compression = Phar::BZ2;

                break;

            default:
                break;
        }
        return '';
    }

    public function getTopDir(): ?File {
        return $this->topDir;
    }

    public function setTopDir(File $topDir): void {
        $this->topDir = $topDir;
    }

    /**
     * Base directory, which will be deleted from each included file (from path).
     * Paths with deleted basedir part are local paths in package.
     */
    public function setBaseDir(File $baseDirectory): void {
        $this->baseDirectory = $baseDirectory;
    }

    /**
     * An alias to assign to the phar package.
     *
     * @param string $alias
     */
    public function setAlias(string $alias): void {
        $this->alias = $alias;
    }

    /**
     * Sets the private key to use to sign the Phar with.
     *
     * @param File $key private key to sign the Phar with
     */
    public function setKey(File $key): void {
        $this->key = $key;
    }

    /**
     * Password for the private key.
     *
     * @param string $keyPassword
     */
    public function setKeyPassword(string $keyPassword): void {
        $this->keyPassword = $keyPassword;
    }

    /**
     * @throws
     */
    protected function build(): void {
        $this->checkPreconditions();

        $fileName = $this->name
            . '-' . $this->version
            . ($this->release ? '-' . $this->release : '');
        $baseFile = $this->topDir->getAbsolutePath()
            . DIRECTORY_SEPARATOR
            . $fileName;

        $pharFile = $baseFile . '.phar';

        $this->project->setNewProperty($this->outFileProperty, $fileName);

        try {
            $this->log(
                'Building package: ' . $pharFile
            );

            // Delete old package, if exists.
            if (file_exists($pharFile)) {
                unlink($pharFile);
            }

            $phar = $this->buildPhar($pharFile);
            $phar->startBuffering();

            $baseDirectory = realpath($this->baseDirectory->getPath());

            foreach ($this->filesets as $fileset) {
                $this->log(
                    'Adding specified files in ' . $fileset->getDir($this->project) . ' to package',
                    Project::MSG_VERBOSE
                );

                $phar->buildFromIterator($fileset, $baseDirectory);
            }

            $phar->stopBuffering();

            // File compression, if needed.
            if (Phar::NONE != $this->compression) {
                $phar->compressFiles($this->compression);
            }

            if (Phar::OPENSSL == $this->signatureAlgorithm) {
                // Load up the contents of the key
                $keyContents = file_get_contents($this->key->getAbsolutePath());

                // Attempt to load the given key as a PKCS#12 Cert Store first.
                if (openssl_pkcs12_read($keyContents, $certs, $this->keyPassword)) {
                    $private = openssl_pkey_get_private($certs['pkey']);
                } else {
                    // Fall back to a regular PEM-encoded private key.
                    // Setup an OpenSSL resource using the private key
                    // and tell the Phar to sign it using that key.
                    $private = openssl_pkey_get_private($keyContents, $this->keyPassword);
                }

                openssl_pkey_export($private, $pkey);
                $phar->setSignatureAlgorithm(Phar::OPENSSL, $pkey);

                // Get the details so we can get the public key and write that out
                // alongside the phar.
                $details = openssl_pkey_get_details($private);
                file_put_contents($baseFile . '.pubkey', $details['key']);
            } else {
                $phar->setSignatureAlgorithm($this->signatureAlgorithm);
            }
        } catch (Exception $e) {
            throw new BuildException(
                'Problem creating package: ' . $e->getMessage(),
                $e,
                $this->getLocation()
            );
        }
    }

    /**
     * @throws
     */
    private function checkPreconditions(): void {
        if ('1' == ini_get('phar.readonly')) {
            throw new BuildException(
                'PharPackageTask require phar.readonly php.ini setting to be disabled'
            );
        }

        if (!extension_loaded('phar')) {
            throw new BuildException(
                "PharPackageTask require either PHP 5.3 or better or the PECL's Phar extension"
            );
        }

        if (null === $this->topDir) {
            throw new BuildException('topDir attribute must be set!', $this->getLocation());
        }

        if ($this->topDir->exists() && !$this->topDir->isDirectory()) {
            throw new BuildException('topDir is NOT a directory!', $this->topDir->getAbsolutePath());
        }

        if (!$this->topDir->canWrite()) {
            throw new BuildException('Can not write to the specified topDir!', $this->topDir->getAbsolutePath());
        }
        if (null !== $this->baseDirectory) {
            if (!$this->baseDirectory->exists()) {
                throw new BuildException(
                    "basedir '" . $this->baseDirectory . "' does not exist!",
                    $this->getLocation()
                );
            }
        }
        if (Phar::OPENSSL == $this->signatureAlgorithm) {
            if (!extension_loaded('openssl')) {
                throw new BuildException(
                    'PHP OpenSSL extension is required for OpenSSL signing of Phars!',
                    $this->getLocation()
                );
            }

            if (null === $this->key) {
                throw new BuildException('key attribute must be set for OpenSSL signing!', $this->getLocation());
            }

            if (!$this->key->exists()) {
                throw new BuildException("key '" . $this->key . "' does not exist!", $this->getLocation());
            }

            if (!$this->key->canRead()) {
                throw new BuildException("key '" . $this->key . "' cannot be read!", $this->getLocation());
            }
        }
    }

    /**
     * Build and configure Phar object.
     */
    private function buildPhar(string $pharFile): Phar {
        $phar = new Phar($pharFile);

        $this->buildBootUp($phar);

        if (null === $this->metadata) {
            $this->createMetaData();
        }

        if ($metadata = $this->metadata->toArray()) {
            $phar->setMetadata($metadata);
        }

        if (!empty($this->alias)) {
            $phar->setAlias($this->alias);
        }

        return $phar;
    }

    protected function buildBootUp(Phar $phar): void {

        $code = '<?php' . PHP_EOL;

        $name = var_export($this->name, true);
        $version = $this->version . '' . ($this->release ? '-' . $this->release : '');
        $version = var_export($version, true);
        $release = var_export($this->release, true);

        $code .= <<<EOQ
use dev\winterframework\core\app\WinterCliArguments;

\$GLOBALS['winter.application.id'] = $name;
\$GLOBALS['winter.application.version'] = $version;
\$GLOBALS['winter.application.release'] = $release;

require_once(__DIR__ . '/vendor/autoload.php');

\$cli = new WinterCliArguments();

EOQ;

        if ($this->summary) {
            $code .= "\$GLOBALS['winter.application.name'] = " . var_export($this->summary, true) . ";\n\n";
        }

        $default = '';
        $stubs = [];
        foreach ($this->cliStubs as $cliStub) {
            if (!$default) {
                $default = $cliStub->getName();
            }
            $stubs[$cliStub->getName()] = str_replace(
                '\\',
                '/',
                $cliStub->getScriptPath()->getPathWithoutBase($this->baseDirectory)
            );
        }

        $code .= '$defaultStub = ' . var_export($default, true) . ';' . PHP_EOL;

        $code .= '$stubs = ';
        $code .= var_export($stubs, true) . ';' . PHP_EOL;

        $code .= <<<EOQ
\$runStub = \$cli->get('stub', '');

if (!\$runStub) {
    \$runStub = \$defaultStub;
}

if (!isset(\$stubs[\$runStub])) {
    throw new RuntimeException('Could not find given Stub ' . \$runStub);
}

\$script = \$stubs[\$runStub];

require(__DIR__ . '/' . \$script);

EOQ;

        $phar['winterBootUp.php'] = $code;

        $phar->setDefaultStub('winterBootUp.php');
    }
}