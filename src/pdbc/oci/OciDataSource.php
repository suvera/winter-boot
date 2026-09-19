<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\coroutine\PoolExhaustedException;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\support\AbstractDataSource;
use dev\winterframework\stereotype\Value;
use PDO;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

class OciDataSource extends AbstractDataSource {

    private static array $defaultOptions = [
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_AUTOCOMMIT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
        PDO::ATTR_PREFETCH => 100,
    ];

    /**
     * Master switch for coroutine-scoped pooling. Defaults on: pooling
     * additionally requires a live Swoole coroutine, so non-coroutine
     * runtimes always use the shared connection below.
     */
    #[Value('${winter.coroutine.db.enabled}', true)]
    protected bool $coroutineDbEnabled = true;

    private array $ociOptions = [];

    private OciConnection $connection;
    private string $dsn;
    private string $username;
    private string $password;

    /**
     * Connections checked out by live coroutines, keyed by coroutine id.
     * A checked-out connection is never reaped or handed to another scope.
     *
     * @var array<int, OciConnection>
     */
    private array $scopedConnections = [];

    /**
     * Returned connections available for reuse by new scopes.
     *
     * @var OciConnection[]
     */
    private array $idleConnections = [];

    /**
     * Coroutine ids with a release hook already registered.
     *
     * @var array<int, bool>
     */
    private array $deferRegistered = [];

    /**
     * Wake-up signals for scopes waiting on a free connection slot.
     * Created lazily so non-Swoole runtimes never touch Swoole classes.
     */
    private ?Channel $releaseSignals = null;

    public function __construct(
        protected DataSourceConfig $config
    ) {
        parent::__construct($config);
        $this->initConfig();
    }

    public function getConnection(): OciConnection {
        $cid = $this->currentCoroutineId();
        if ($cid === null) {
            return $this->sharedConnection();
        }
        try {
            return $this->scopedConnection($cid);
        } catch (PoolExhaustedException $e) {
            throw $e;
        } catch (Throwable $e) {
            self::logException($e, 'Coroutine DB pool failure, using shared connection');
            return $this->sharedConnection();
        }
    }

    /**
     * Reap idle connections. Only returned (idle) connections and the
     * process-wide shared connection are reaped here; a connection checked
     * out by a live coroutine is never touched.
     */
    public function checkIdleConnection(): void {
        foreach ($this->idleConnections as $key => $conn) {
            try {
                $conn->checkIdleConnection();
            } catch (Throwable $e) {
                self::logException($e);
            }
            if ($conn->isClosed()) {
                unset($this->idleConnections[$key]);
            }
        }
        if (isset($this->connection)) {
            try {
                $this->connection->checkIdleConnection();
            } catch (Throwable $e) {
                self::logException($e);
            }
        }
    }

    /**
     * Coroutine id of the caller, or null when coroutine-scoped pooling
     * does not apply (disabled, no Swoole, or outside any coroutine).
     */
    private function currentCoroutineId(): ?int {
        if (!$this->coroutineDbEnabled) {
            return null;
        }
        if (!extension_loaded('swoole') || !class_exists(Coroutine::class)) {
            return null;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return null;
        }
        if ($cid === false || $cid === -1) {
            return null;
        }
        return (int)$cid;
    }

    /**
     * Check out the calling coroutine's connection, creating and binding
     * one on first touch. The same coroutine always gets the same instance.
     *
     * @throws PoolExhaustedException when no slot frees up within maxWaitMs
     */
    private function scopedConnection(int $cid): OciConnection {
        $existing = $this->scopedConnections[$cid] ?? null;
        if ($existing !== null) {
            if (!$existing->isClosed()) {
                $existing->touch();
                return $existing;
            }
            unset($this->scopedConnections[$cid]);
        }
        $this->awaitSlot();
        $conn = $this->takeIdle() ?? $this->createConnection();
        $this->scopedConnections[$cid] = $conn;
        if (!isset($this->deferRegistered[$cid])) {
            $this->deferRegistered[$cid] = true;
            try {
                Coroutine::defer(function () use ($cid): void {
                    $this->releaseConnection($cid);
                });
            } catch (Throwable $e) {
                unset($this->deferRegistered[$cid]);
                self::logException($e, 'Could not register coroutine connection release');
            }
        }
        return $conn;
    }

    /**
     * Return a scope's connection to the idle pool and wake one waiter.
     * Runs as the coroutine-end hook, so the slot can never leak into a
     * recycled coroutine id.
     */
    private function releaseConnection(int $cid): void {
        unset($this->deferRegistered[$cid]);
        $conn = $this->scopedConnections[$cid] ?? null;
        unset($this->scopedConnections[$cid]);
        if ($conn !== null && !$conn->isClosed()) {
            $this->idleConnections[] = $conn;
        }
        $this->signalRelease();
    }

    private function takeIdle(): ?OciConnection {
        while (!empty($this->idleConnections)) {
            $conn = array_pop($this->idleConnections);
            if (!$conn->isClosed()) {
                $conn->touch();
                return $conn;
            }
        }
        return null;
    }

    private function sharedConnection(): OciConnection {
        if (!isset($this->connection)) {
            $this->connection = $this->createConnection();
        }
        return $this->connection;
    }

    private function createConnection(): OciConnection {
        $conn = new OciConnection(
            $this->dsn,
            $this->username,
            $this->password,
            $this->ociOptions
        );
        $this->validateConnection($conn);
        return $conn;
    }

    private function effectiveMaxConnections(): int {
        return max(0, $this->config->getMaxConnections());
    }

    private function effectiveMaxWaitMs(): int {
        return max(0, $this->config->getMaxWaitMs());
    }

    /**
     * Block until an idle connection or a free slot exists, or maxWaitMs
     * passes.
     *
     * @throws PoolExhaustedException
     */
    private function awaitSlot(): void {
        $max = $this->effectiveMaxConnections();
        if ($max <= 0) {
            return;
        }
        $deadline = microtime(true) + $this->effectiveMaxWaitMs() / 1000;
        while (empty($this->idleConnections)
            && count($this->scopedConnections) + count($this->idleConnections) >= $max
        ) {
            $remainingMs = (int)(($deadline - microtime(true)) * 1000);
            if ($remainingMs <= 0 || !$this->waitForRelease($remainingMs)) {
                throw new PoolExhaustedException(
                    $this->config->getName(),
                    count($this->scopedConnections),
                    $max
                );
            }
        }
    }

    /**
     * Yield until a release signal arrives or the timeout passes. Returns
     * false when waiting is impossible so the caller fails fast.
     *
     * Note: Swoole turns out-of-coroutine Channel use into a non-catchable
     * fatal, so no try/catch can protect the pop() below. The
     * Coroutine::getCid() reality check is load-bearing: it verifies with
     * Swoole itself that waiting is safe.
     */
    private function waitForRelease(int $timeoutMs): bool {
        if (!extension_loaded('swoole') || !class_exists(Channel::class)) {
            return false;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return false;
        }
        if ($cid === false || $cid === -1) {
            return false;
        }
        if ($this->releaseSignals === null) {
            try {
                $this->releaseSignals = new Channel(1);
            } catch (Throwable) {
                return false;
            }
        }
        try {
            return $this->releaseSignals->pop(max($timeoutMs, 1) / 1000) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function signalRelease(): void {
        if ($this->releaseSignals === null) {
            return;
        }
        if (!extension_loaded('swoole')) {
            return;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return;
        }
        if ($cid === false || $cid === -1) {
            return;
        }
        try {
            if ($this->releaseSignals->isFull()) {
                return;
            }
            $this->releaseSignals->push(true);
        } catch (Throwable) {
        }
    }

    private function initConfig(): void {
        $this->dsn = $this->config->getUrl();
        $this->username = $this->config->getUsername();
        $this->password = $this->config->getPassword();

        if (!isset($_ENV['NLS_LANG']) || empty($_ENV['NLS_LANG'])) {
            self::$defaultOptions['NLS_LANG'] = 'AMERICAN_AMERICA.UTF8';
        } else {
            self::$defaultOptions['NLS_LANG'] = $_ENV['NLS_LANG'];
        }

        if (!isset($_ENV['NLS_DATE_FORMAT']) || empty($_ENV['NLS_DATE_FORMAT'])) {
            self::$defaultOptions['NLS_DATE_FORMAT'] = 'YYYY-MM-DD HH24:MI:SS';
        } else {
            self::$defaultOptions['NLS_DATE_FORMAT'] = $_ENV['NLS_DATE_FORMAT'];
        }

        $this->ociOptions = self::$defaultOptions;
        $this->ociOptions[PDO::ATTR_PERSISTENT] = $this->config->isPersistent();
        $this->ociOptions[PDO::ATTR_TIMEOUT] = $this->config->getTimeoutSecs();
        $this->ociOptions[PDO::ATTR_AUTOCOMMIT] = $this->config->isAutoCommit();
        $this->ociOptions[PDO::ATTR_PREFETCH] = $this->config->getRowsPrefetch();
        $this->ociOptions[PDO::ATTR_PREFETCH] = $this->config->getRowsPrefetch();
        $this->ociOptions['idleTimeout'] = $this->config->getIdleTimeout();
    }

    private function validateConnection(OciConnection $connection): void {
        $validateSql = $this->config->getValidationQuery();
        if (empty($validateSql)) {
            return;
        }

        $stmt = $connection->createStatement();
        $resultSet = $stmt->executeQuery($validateSql);
        $resultSet->next();

        self::logInfo("ValidationQuery:" . $resultSet->getString(0));
        $stmt->close();
        $resultSet = null;
    }

}
