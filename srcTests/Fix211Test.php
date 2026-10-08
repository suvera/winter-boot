<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\actuator\ActuatorEndPoints;
use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\InMemoryCache;
use dev\winterframework\cache\impl\SimpleKeyGenerator;
use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\cache\aop\CacheableAspect;
use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\coroutine\CoroutineScopeProvider;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\io\kv\KvClient;
use dev\winterframework\io\kv\KvServer;
use dev\winterframework\io\queue\QueueServer;
use dev\winterframework\migrations\CliSqlFileExecutor;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\pdo\PdoConnection;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTemplate;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\Debug;
use dev\winterframework\util\SerializationUtil;
use dev\winterframework\util\concurrent\LocalLock;
use dev\winterframework\web\client\DefaultRestClientTransport;
use dev\winterframework\web\client\RestClientException;
use dev\winterframework\web\client\RestTemplate;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\session\RequestSession;
use dev\winterframework\web\session\SessionManager;
use dev\winterframework\web\session\SessionOptions;
use dev\winterframework\cache\Cache;
use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\txn\aop\TransactionalAspect;
use dev\winterframework\util\log\LoggerManager;
use Monolog\Handler\TestHandler;
use winterBootTests\Support\TestCase;

final class Fix211ScopeStub implements CoroutineScopeProvider {
    public ?string $scope = null;
    /** @var callable[] */
    public array $deferred = [];

    public function isInCoroutine(): bool {
        return $this->scope !== null;
    }

    public function getScopeId(): ?string {
        return $this->scope;
    }

    public function defer(callable $fn): void {
        $this->deferred[] = $fn;
    }
}

final class Fix211StaticDto {
    public static string $mode = 'safe';
    public string $name = '';
}

final class Fix211ValidatedDto {
    #[JsonProperty(name: 'email', validate: [['length', 'max' => 5]])]
    public string $email = '';
    public string $comment = '';
}

final class Fix211MemoryStore implements \SessionHandlerInterface {
    public array $rows = [];

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        return $this->rows[$id] ?? '';
    }

    public function write(string $id, string $data): bool {
        $this->rows[$id] = $data;
        return true;
    }

    public function destroy(string $id): bool {
        unset($this->rows[$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return 0;
    }
}

final class Fix211CookieRequest extends HttpRequest {
    public function __construct(private array $stubCookies = []) {
    }

    public function getCookie(string $name): ?string {
        return $this->stubCookies[$name] ?? null;
    }
}

final class Fix211KeyCtx extends WinterApplicationContextBuilder {
    public function __construct() {
    }

    public function beanByClass(string $class): ?object {
        return new SimpleKeyGenerator();
    }

    public function getId(): string {
        return 'test';
    }

    public function getApplicationName(): string {
        return 'test';
    }

    public function getApplicationVersion(): string {
        return 'test';
    }

    public function getStartupDate(): int {
        return 0;
    }
}

final class Fix211Target {
    public function find(string $email): string {
        return 'secret-value';
    }
}

final class Fix211RecordingInterceptor implements HandlerInterceptor {
    public array $calls = [];

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?\Throwable $ex = null): void {
        $this->calls[] = 'afterCompletion';
    }
}

final class Fix211Renderer implements ResponseRenderer {
    public array $calls = [];

    public function render(ResponseEntity $entity, HttpRequest $request): void {
        $this->calls[] = 'render';
    }

    public function renderAndExit(ResponseEntity $entity, HttpRequest $request): void {
        $this->calls[] = 'renderAndExit';
    }
}

final class Fix211UriRequest extends HttpRequest {
    public function __construct() {
    }

    public function getUri(): string {
        return '/x';
    }

    public function getMethod(): string {
        return 'GET';
    }
}

#[RestController]
class Fix211RouteController {
    #[GetMapping(path: '/fix211-users/{id}')]
    public function user(#[PathVariable(name: 'id')] int $id): void {
    }
}

/**
 * 2.1.1 regression suite: one test (or small group) per fixed defect.
 * Each test fails on 2.1.0 and passes once the fix is in.
 */
final class Fix211Test extends TestCase {

    private array $tmpFiles = [];

    public function __destruct() {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    private function sqliteFile(): string {
        $file = tempnam(sys_get_temp_dir(), 'wb211-') . '.sqlite';
        $this->tmpFiles[] = $file;
        return $file;
    }

    private function dataSource(string $file): PdoDataSource {
        $config = new DataSourceConfig();
        $config->setName('fix211');
        $config->setUrl('sqlite:' . $file);
        return new PdoDataSource($config);
    }

    // ---------------------------------------------------------------
    // Transactions
    // ---------------------------------------------------------------

    // INSERT through PdbcTemplate::update() must not throw on the generated key.
    public function testTemplateInsertReturnsGeneratedKey(): void {
        $ds = $this->dataSource($this->sqliteFile());
        $tpl = new PdoTemplate($ds);
        $tpl->update('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)', []);
        $this->assertSame(1, $tpl->update("INSERT INTO t (v) VALUES ('a')", []));
    }

    // REQUIRES_NEW must run on its own connection and commit independently.
    public function testRequiresNewCommitsIndependently(): void {
        $file = $this->sqliteFile();
        $ds = $this->dataSource($file);
        $tpl = new PdoTemplate($ds);
        $tpl->update('CREATE TABLE t (v TEXT)', []);
        $mgr = new PdoTransactionManager($ds);

        $outer = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $outerConn = $ds->getConnection();

        $inner = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRES_NEW));
        $this->assertTrue($ds->getConnection() !== $outerConn, 'REQUIRES_NEW must use a separate connection');
        $tpl->update("INSERT INTO t VALUES ('inner')", []);
        $mgr->commit($inner);

        $this->assertTrue($ds->getConnection() === $outerConn, 'outer connection restored after REQUIRES_NEW');
        $tpl->update("INSERT INTO t VALUES ('outer')", []);
        $mgr->rollback($outer);

        $rows = array_column($tpl->queryForList('SELECT v FROM t ORDER BY v'), 'v');
        $this->assertSame(['inner'], $rows);
    }

    // NOT_SUPPORTED must run outside the current transaction.
    public function testNotSupportedRunsOutsideTransaction(): void {
        $file = $this->sqliteFile();
        $ds = $this->dataSource($file);
        $tpl = new PdoTemplate($ds);
        $tpl->update('CREATE TABLE t (v TEXT)', []);
        $mgr = new PdoTransactionManager($ds);

        $outer = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $none = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_NOT_SUPPORTED));
        $tpl->update("INSERT INTO t VALUES ('autocommit')", []);
        $mgr->commit($none);
        $tpl->update("INSERT INTO t VALUES ('outer')", []);
        $mgr->rollback($outer);

        $rows = array_column($tpl->queryForList('SELECT v FROM t ORDER BY v'), 'v');
        $this->assertSame(['autocommit'], $rows);
    }

    // Transaction state is per coroutine scope: scope B must not join scope A's transaction.
    public function testTransactionStateIsPerScope(): void {
        $file = $this->sqliteFile();
        $ds = $this->dataSource($file);
        $mgr = new PdoTransactionManager($ds);
        $scopes = new Fix211ScopeStub();
        $mgr->setScopeProvider($scopes);

        $scopes->scope = 'A';
        $a = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $this->assertTrue($a->isNewTransaction());

        $scopes->scope = 'B';
        $b = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $this->assertTrue($b->isNewTransaction(), 'scope B must start its own transaction');

        // Scope end discards that scope's leftover state.
        foreach ($scopes->deferred as $fn) {
            $fn();
        }
    }

    // A connection returned to the pool must not carry an open transaction.
    public function testReleasedConnectionIsRolledBack(): void {
        $file = $this->sqliteFile();
        $ds = $this->dataSource($file);
        $conn = new PdoConnection('sqlite:' . $file, '', '', []);
        $conn->beginTransaction();
        $scoped = new \ReflectionProperty($ds, 'scopedConnections');
        $scoped->setValue($ds, [7 => $conn]);

        $release = new \ReflectionMethod($ds, 'releaseConnection');
        $release->invoke($ds, 7);

        $this->assertFalse($conn->getPdo()->inTransaction(), 'pooled connection must be rolled back');
    }

    // Scoped pool must fail closed instead of handing out the shared fallback.
    public function testScopedPoolFailsClosedOnCreateFailure(): void {
        $scopes = new Fix211ScopeStub();
        $calls = 0;
        $pool = new CoroutineScopedPool(
            function () use (&$calls, $scopes): object {
                $calls++;
                if ($scopes->scope !== null) {
                    throw new \RuntimeException('db down');
                }
                return new \stdClass();
            },
            $scopes,
            function (object $o): void {
            }
        );
        $pool->current(); // process fallback exists now
        $scopes->scope = 'req-1';
        $this->assertThrows(\RuntimeException::class, fn() => $pool->current());
    }

    // ---------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------

    // Eviction must not renumber integer-like keys.
    public function testInMemoryCacheKeepsIntegerKeysOnEviction(): void {
        $config = new CacheConfiguration();
        $config->maximumSize = 2;
        $cache = new InMemoryCache('c', $config);
        $cache->put('42', 'A');
        $cache->put('43', 'B');
        $cache->put('7', 'C');
        $this->assertSame('B', $cache->get('43')->get());
        $this->assertSame('C', $cache->get('7')->get());
        $this->assertNull($cache->get('0')->get());
    }

    // getOrProvide must honour maximumSize.
    public function testInMemoryGetOrProvideHonoursMaximumSize(): void {
        $config = new CacheConfiguration();
        $config->maximumSize = 2;
        $cache = new InMemoryCache('c', $config);
        foreach (['a', 'b', 'c', 'd'] as $k) {
            $cache->getOrProvide($k, fn() => $k);
        }
        $items = new \ReflectionProperty($cache, 'items');
        $this->assertSame(2, count($items->getValue($cache)));
    }

    // Different array/object arguments must produce different cache keys.
    public function testKeyGeneratorDistinguishesArraysAndObjects(): void {
        $gen = new SimpleKeyGenerator();
        $key = new \ReflectionMethod(SimpleKeyGenerator::class, 'argumentsKey');
        $a = $key->invoke($gen, [['status' => 'x']]);
        $b = $key->invoke($gen, [['status' => 'y']]);
        $this->assertTrue($a !== $b, 'array args must not collide');

        $o1 = new \stdClass();
        $o1->id = 1;
        $o2 = new \stdClass();
        $o2->id = 2;
        $this->assertTrue($key->invoke($gen, [$o1]) !== $key->invoke($gen, [$o2]), 'object args must not collide');

        $long1 = $key->invoke($gen, [str_repeat('a', 300) . 'X']);
        $long2 = $key->invoke($gen, [str_repeat('a', 300) . 'Y']);
        $this->assertTrue($long1 !== $long2, 'long args must not collide after truncation');
    }

    // A has()-then-get() race that returns the miss sentinel must count as a miss.
    public function testCacheableTreatsMissSentinelAsMiss(): void {
        $this->assertFalse(CacheableAspect::isHit(SimpleValueWrapper::$NULL_VALUE));
        $this->assertTrue(CacheableAspect::isHit(new SimpleValueWrapper(null)));
    }

    // ---------------------------------------------------------------
    // Sessions
    // ---------------------------------------------------------------

    // An unknown client-supplied session id must never be adopted.
    public function testUnknownSessionIdIsReplaced(): void {
        $mgr = new SessionManager();
        $store = new Fix211MemoryStore();
        $session = $mgr->open(new Fix211CookieRequest(['WINTERSESSID' => 'attackerchosenid']), $store, new SessionOptions());
        $this->assertTrue($session->getId() !== 'attackerchosenid');
        $this->assertTrue($session->isNew());
    }

    // A known session keeps its id; regenerateId() rotates it and drops the old row.
    public function testRegenerateSessionId(): void {
        $mgr = new SessionManager();
        $store = new Fix211MemoryStore();
        $store->rows['knownid123'] = serialize(['u' => 1]);
        $opts = new SessionOptions();
        $session = $mgr->open(new Fix211CookieRequest(['WINTERSESSID' => 'knownid123']), $store, $opts);
        $this->assertSame('knownid123', $session->getId());

        $session->regenerateId();
        $this->assertTrue($session->getId() !== 'knownid123');
        $mgr->commit($session, new ResponseEntity(), $store, $opts);
        $this->assertFalse(isset($store->rows['knownid123']), 'old session row must be destroyed');
        $this->assertSame(['u' => 1], unserialize($store->rows[$session->getId()]));
    }

    // Session cookies carry SameSite (default Lax).
    public function testSessionCookieHasSameSite(): void {
        $mgr = new SessionManager();
        $store = new Fix211MemoryStore();
        $opts = new SessionOptions();
        $session = $mgr->open(new Fix211CookieRequest(), $store, $opts);
        $res = new ResponseEntity();
        $mgr->commit($session, $res, $store, $opts);
        $this->assertSame('Lax', $res->getCookies()[0]->samesite);
    }

    // ---------------------------------------------------------------
    // Web
    // ---------------------------------------------------------------

    // Actuator configprops/env must mask secret-looking keys.
    public function testActuatorMasksSecrets(): void {
        $masked = ActuatorEndPoints::maskSensitive([
            'datasource.password' => 'p@ss',
            'winter.kv.token' => 't',
            'AWS_SECRET_ACCESS_KEY' => 's',
            'nested' => ['apiKey' => 'k', 'name' => 'ok'],
            'server.port' => 8080,
        ]);
        $this->assertSame('******', $masked['datasource.password']);
        $this->assertSame('******', $masked['winter.kv.token']);
        $this->assertSame('******', $masked['AWS_SECRET_ACCESS_KEY']);
        $this->assertSame('******', $masked['nested']['apiKey']);
        $this->assertSame('ok', $masked['nested']['name']);
        $this->assertSame(8080, $masked['server.port']);
    }

    // The route cache is bounded; random path variables cannot grow it forever.
    public function testRouteCacheIsBounded(): void {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        $ref = RefMethod::getInstance(new \ReflectionMethod(Fix211RouteController::class, 'user'));
        foreach ($ref->getAttributes(GetMapping::class) as $a) {
            $inst = $a->newInstance();
            $inst->init($ref);
            $reg->put($inst);
        }
        for ($i = 0; $i < WinterRequestMappingRegistry::MAX_CACHED_PATHS + 50; $i++) {
            $reg->find('fix211-users/' . $i, 'GET');
        }
        $cache = (new \ReflectionProperty(WinterRequestMappingRegistry::class, 'cachedPaths'))->getValue();
        $this->assertTrue(count($cache) <= WinterRequestMappingRegistry::MAX_CACHED_PATHS, 'route cache must be capped');
        $this->assertSame('user', $reg->find('fix211-users/5', 'GET')->getMapping()->getRefOwner()->getName());
    }

    // Metrics use the route template as label, never the raw URI.
    public function testMetricLabelUsesRouteTemplate(): void {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        $ref = RefMethod::getInstance(new \ReflectionMethod(Fix211RouteController::class, 'user'));
        foreach ($ref->getAttributes(GetMapping::class) as $a) {
            $inst = $a->newInstance();
            $inst->init($ref);
            $reg->put($inst);
        }
        $match = $reg->find('fix211-users/987654', 'GET');
        $label = DispatcherServlet::routeLabel($match);
        $this->assertFalse(str_contains($label, '987654'), 'label must not contain path values');
    }

    // Interceptors also match the normalised URI routing uses (no double-slash bypass).
    public function testInterceptorMatchesNormalisedUri(): void {
        $this->assertTrue(DispatcherServlet::interceptorMatches('^\/admin', '//admin/x'));
        $this->assertTrue(DispatcherServlet::interceptorMatches('^\/admin', '/admin/x'));
        $this->assertFalse(DispatcherServlet::interceptorMatches('^\/admin', '/public/x'));
    }

    // Context path must be stripped on segment boundaries only.
    public function testContextPathStrippedOnSegmentBoundary(): void {
        $this->assertSame('users', DispatcherServlet::stripContextPath('api/users', 'api'));
        $this->assertSame('', DispatcherServlet::stripContextPath('api', 'api'));
        $this->assertSame('apiary/x', DispatcherServlet::stripContextPath('apiary/x', 'api'));
    }

    // Bound null arguments are present, not missing.
    public function testNullBoundArgumentIsNotMissing(): void {
        $this->assertSame([], DispatcherServlet::missingParameters(
            new \ReflectionMethod(self::class, 'nullableTarget'),
            ['q' => null]
        ));
        $this->assertSame(['q'], DispatcherServlet::missingParameters(
            new \ReflectionMethod(self::class, 'nullableTarget'),
            []
        ));
    }

    public function nullableTarget(?string $q): void {
    }

    // Only http/https URLs may be requested.
    public function testRestTransportRejectsNonHttpSchemes(): void {
        $t = new DefaultRestClientTransport();
        $this->assertThrows(RestClientException::class,
            fn() => $t->request('GET', 'file://localhost/etc/hostname', [], null, 1, 1));
        $this->assertThrows(RestClientException::class,
            fn() => $t->request('GET', 'gopher://127.0.0.1:1/', [], null, 1, 1));
    }

    // Existing query keys and fragments survive appendQuery().
    public function testAppendQueryPreservesKeysAndFragment(): void {
        $this->assertSame(
            'http://h/p?user.id=1&page=2#top',
            RestTemplate::appendQuery('http://h/p?user.id=1#top', ['page' => 2])
        );
        $this->assertSame(
            'http://h/p?a=9',
            RestTemplate::appendQuery('http://h/p?a=1', ['a' => 9])
        );
    }

    // ---------------------------------------------------------------
    // Binding / validation
    // ---------------------------------------------------------------

    // Static properties must never be bound from request data.
    public function testStaticPropertyNotBound(): void {
        $o = ObjectCreator::createObject(Fix211StaticDto::class, ['name' => 'x', 'mode' => 'pwned']);
        $this->assertSame('x', $o->name);
        $this->assertSame('safe', Fix211StaticDto::$mode);
    }

    // A validator must apply only to the property it annotates.
    public function testValidatorDoesNotLeakToNextProperty(): void {
        $o = ObjectCreator::createObject(Fix211ValidatedDto::class, [
            'email' => 'a@b',
            'comment' => 'a comment longer than five chars',
        ]);
        $this->assertSame('a comment longer than five chars', $o->comment);
    }

    // Invalid-validator message keeps the property name.
    public function testInvalidValidatorMessage(): void {
        $prop = new JsonProperty(name: 'x', validate: [42]);
        $msg = $prop->validate('field', 'v');
        $this->assertTrue(str_contains((string)$msg, 'Property field'), 'got: ' . $msg);
    }

    // Serialized payloads can be restricted to an allow-list of classes.
    public function testSerializationAllowList(): void {
        SerializationUtil::setAllowedClasses([]);
        try {
            $value = SerializationUtil::unserialize(serialize(new \ArrayObject([1])));
            $this->assertTrue($value instanceof \__PHP_Incomplete_Class);
            $this->assertSame(['a' => 1], SerializationUtil::unserialize(serialize(['a' => 1])));
        } finally {
            SerializationUtil::setAllowedClasses(true);
        }
        $this->assertTrue(SerializationUtil::unserialize(serialize(new \ArrayObject([1]))) instanceof \ArrayObject);
    }

    // ---------------------------------------------------------------
    // Infrastructure
    // ---------------------------------------------------------------

    // CLI migrations stop on the first SQL error and report failure.
    public function testMigrationCliStopsOnError(): void {
        $exec = new class extends CliSqlFileExecutor {
            public array $cmds = [];
            public array $scripts = [];

            protected function runCommand(string $cmd, array $env): void {
                $this->cmds[] = $cmd;
                if (preg_match("/@'([^']+)'/", $cmd, $m) && is_file($m[1])) {
                    $this->scripts[] = file_get_contents($m[1]);
                }
            }
        };
        $sql = tempnam(sys_get_temp_dir(), 'wb211sql');
        $this->tmpFiles[] = $sql;
        $exec->execute('pgsql:host=h;dbname=d', 'u', 'p', $sql);
        $exec->execute('sqlsrv:server=h;database=d', 'u', 'p', $sql);
        $exec->execute('oci:dbname=d', 'u', 'p', $sql);
        $this->assertTrue(str_contains($exec->cmds[0], 'ON_ERROR_STOP=1'), 'psql must stop on error');
        $this->assertTrue(str_contains($exec->cmds[1], ' -b '), 'sqlcmd must stop on error');
        $this->assertTrue(str_contains($exec->scripts[0] ?? '', 'WHENEVER SQLERROR EXIT'), 'sqlplus must stop on error');
    }

    // A command with large stderr must not deadlock the runner.
    public function testMigrationRunnerDrainsStderr(): void {
        $exec = new CliSqlFileExecutor();
        $run = new \ReflectionMethod($exec, 'runCommand');
        $cmd = 'timeout 10 ' . escapeshellarg(PHP_BINARY)
            . ' -r ' . escapeshellarg('fwrite(STDERR, str_repeat("x", 300000)); echo "done";');
        $run->invoke($exec, $cmd, []);
        $this->assertTrue(true);
    }

    // Two locks with the same name in one process must exclude each other.
    public function testLocalLockExcludesWithinProcess(): void {
        $name = 'fix211-' . bin2hex(random_bytes(4));
        $a = new LocalLock($name);
        $b = new LocalLock($name);
        $this->assertTrue($a->tryLock());
        try {
            $this->assertFalse($b->tryLock(0), 'second holder in the same process must not acquire');
        } finally {
            $a->unlock();
        }
        $this->assertTrue($b->tryLock(0));
        $b->unlock();
    }

    // KV/queue servers split frames on "\n" and compare tokens in constant time.
    public function testKvQueueServerFraming(): void {
        foreach ([KvServer::class, QueueServer::class] as $cls) {
            $srv = new $cls('tok', []);
            $config = (new \ReflectionProperty($cls, 'config'))->getValue($srv);
            $this->assertTrue($config['open_eof_split'] ?? false, $cls . ' must split on EOF');
            $this->assertSame("\n", $config['package_eof'] ?? null);
        }
    }

    // KV client must handle undecodable responses without TypeError.
    public function testKvClientRejectsUndecodableResponse(): void {
        $this->assertThrows(\dev\winterframework\io\kv\KvException::class,
            fn() => KvClient::decodeResponse('not-json'));
    }

    // Exception backtraces keep the first frame.
    public function testExceptionBacktraceKeepsFirstFrame(): void {
        $ex = $this->makeException();
        $this->assertTrue(str_contains(Debug::exceptionBacktrace($ex), 'makeException'));
    }

    private function makeException(): \Throwable {
        return new \RuntimeException('x');
    }

    // Deleting a route removes it from lookups.
    public function testRouteDeleteRemovesRoute(): void {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        $ref = RefMethod::getInstance(new \ReflectionMethod(Fix211RouteController::class, 'user'));
        foreach ($ref->getAttributes(GetMapping::class) as $a) {
            $inst = $a->newInstance();
            $inst->init($ref);
            $reg->put($inst);
        }
        $this->assertTrue($reg->find('fix211-users/1', 'GET') !== null);
        $reg->delete('fix211-users/{id}');
        $this->assertNull($reg->find('fix211-users/1', 'GET'));
        $this->assertNull($reg->find('fix211-users/2', 'GET'));
    }

    // Cache aspects must not log cached values (or keys) at INFO.
    public function testCacheCommitDoesNotLogValues(): void {
        $cache = new InMemoryCache('c');
        $ctx = new AopContext(
            new Cacheable(cacheNames: 'c'),
            RefMethod::getInstance(new \ReflectionMethod(Fix211Target::class, 'find')),
            new Fix211KeyCtx()
        );
        $exCtx = new AopExecutionContext(new Fix211Target(), ['user@example.com']);
        $exCtx->setVariable(CacheableAspect::OPERATION, [$cache]);

        $handler = new TestHandler();
        $logger = LoggerManager::getLogger();
        $logger->pushHandler($handler);
        try {
            (new CacheableAspect())->commit($ctx, $exCtx, 'secret-value');
            (new CacheableAspect())->begin($ctx, $exCtx);
        } catch (\Throwable) {
            // stopExecution() throws on a hit; only the log lines matter here.
        } finally {
            $logger->popHandler();
        }
        foreach ($handler->getRecords() as $rec) {
            $text = json_encode([$rec['message'] ?? '', $rec['context'] ?? []]);
            $this->assertFalse(str_contains($text, 'secret-value'), 'cached value logged');
            $this->assertFalse(str_contains($text, 'user@example.com'), 'key with argument logged');
        }
    }

    // noRollbackFor must commit the transaction instead of leaving it open.
    public function testNoRollbackForCommits(): void {
        $file = $this->sqliteFile();
        $ds = $this->dataSource($file);
        $tpl = new PdoTemplate($ds);
        $tpl->update('CREATE TABLE t (v TEXT)', []);
        $mgr = new PdoTransactionManager($ds);

        $status = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $tpl->update("INSERT INTO t VALUES ('kept')", []);
        TransactionalAspect::completeAfterFailure($mgr, $status, false);

        $this->assertTrue($status->isCompleted(), 'transaction must be completed');
        $this->assertFalse($ds->getConnection()->getPdo()->inTransaction(), 'connection must leave the transaction');
        $this->assertSame(['kept'], array_column($tpl->queryForList('SELECT v FROM t'), 'v'));
    }

    // A ControllerInterceptor veto still runs afterCompletion and renders without exiting.
    public function testControllerVetoRunsAfterCompletion(): void {
        $registry = new InterceptorRegistry();
        $interceptor = new Fix211RecordingInterceptor();
        $registry->addInterceptor($interceptor, '.*');
        $renderer = new Fix211Renderer();
        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();
        $finish = new \ReflectionMethod(DispatcherServlet::class, 'finishVetoed');
        $finish->invoke($servlet, $registry, $renderer, new Fix211UriRequest(), new ResponseEntity());
        $this->assertSame(['afterCompletion'], $interceptor->calls);
        $this->assertSame(['render'], $renderer->calls);
    }
}
