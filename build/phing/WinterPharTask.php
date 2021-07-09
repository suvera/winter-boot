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

    protected File $destinationFile;
    protected int $compression = Phar::NONE;
    protected File $baseDirectory;
    protected File $key;
    protected string $keyPassword = '';
    protected int $signatureAlgorithm = Phar::SHA1;
    protected array $filesets = [];
    protected PharMetadata $metadata;
    protected string $alias;


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

    /**
     * Destination (output) file.
     */
    public function setDestFile(File $destinationFile): void {
        $this->destinationFile = $destinationFile;
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

        try {
            $this->log(
                'Building package: ' . $this->destinationFile->__toString()
            );

            // Delete old package, if exists.
            if ($this->destinationFile->exists()) {
                $this->destinationFile->delete();
            }

            $phar = $this->buildPhar();
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
                file_put_contents($this->destinationFile . '.pubkey', $details['key']);
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

        if (null === $this->destinationFile) {
            throw new BuildException('destfile attribute must be set!', $this->getLocation());
        }

        if ($this->destinationFile->exists() && $this->destinationFile->isDirectory()) {
            throw new BuildException('destfile is a directory!', $this->getLocation());
        }

        if (!$this->destinationFile->canWrite()) {
            throw new BuildException('Can not write to the specified destfile!', $this->getLocation());
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
    private function buildPhar(): Phar {
        $phar = new Phar($this->destinationFile->getAbsolutePath());

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

        $winterRelDir = str_replace($this->buildBaseDir, '', $this->winterDir);
        $winterRelDir = str_replace('\\', '/', $winterRelDir);
        $winterRelDir = trim($winterRelDir, '/');

        if ($winterRelDir) {
            $winterRelDir = '/' . $winterRelDir;
        }

        $code .= <<<EOQ
use dev\winterframework\core\app\WinterCliArguments;

require_once(__DIR__ . '$winterRelDir/vendor/autoload.php');

\$cli = new WinterCliArguments();


EOQ;

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