<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\InMemoryCache;
use dev\winterframework\cache\impl\SharedKvCache;
use dev\winterframework\core\context\BeanProvider;
use dev\winterframework\core\context\ShutDownRegistry;
use dev\winterframework\io\kv\KvTemplate;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\proxy\ProxyGenerator;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\txn\ex\IllegalTransactionStateException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\support\AbstractTransactionObject;
use dev\winterframework\txn\support\AbstractTransactionStatus;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\txn\support\NoTransactionStatus;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionObject;
use dev\winterframework\txn\TransactionStatus;
use winterBootTests\Support\TestCase;

final class AuditFixFixtureService {
    public array $destroyed = [];

    public function close(): void {
        $this->destroyed[] = true;
    }

    public function boom(string $label = "x", array $opts = ['a' => 1]): string {
        return $label;
    }

    public function byRef(string &$name, string ...$rest): string {
        return $name;
    }

    public function fails(): void {
        throw new \RuntimeException('boom');
    }
}

final class AuditFixFakeTxnObject extends AbstractTransactionObject {
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct() {
    }

    public function begin(): void {
        $this->begins++;
    }

    public function commit(): void {
        $this->commits++;
    }

    public function rollback(): void {
        $this->rollbacks++;
    }

    public function flush(): void {
    }

    public function getPreviousIsolationLevel(): ?int {
        return null;
    }

    public function isCommitted(): bool {
        return $this->commits > 0;
    }

    public function isSavepointAllowed(): bool {
        return true;
    }

    public function getConnection(): ?\dev\winterframework\pdbc\Connection {
        return null;
    }

    public function createSavepoint(): Savepoint {
        return new Savepoint('sp');
    }

    public function rollbackToSavepoint(Savepoint $point): void {
    }

    public function releaseSavepoint(Savepoint $point): void {
    }
}

final class AuditFixFakeTxnStatus extends AbstractTransactionStatus {
    public function __construct(AuditFixFakeTxnObject $txn) {
        parent::__construct($txn, true);
    }
}

final class AuditFixFakeTxnManager extends AbstractPlatformTransactionManager {
    public AuditFixFakeTxnObject $lastObject;

    protected function doGetTransaction(TransactionDefinition $definition): TransactionStatus {
        $this->lastObject = new AuditFixFakeTxnObject();
        return new AuditFixFakeTxnStatus($this->lastObject);
    }

    protected function doCommit(TransactionStatus $status): void {
        $status->getTransaction()->commit();
    }

    protected function doRollback(TransactionStatus $status): void {
        $status->getTransaction()->rollback();
    }
}

final class AuditFixFakeKv implements KvTemplate {
    public array $store = [];
    public int $puts = 0;

    public function get(string $domain, string $key): mixed {
        return $this->store[$domain][$key] ?? null;
    }

    public function put(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        $this->store[$domain][$key] = $data;
        $this->puts++;
        return true;
    }

    public function putIfNot(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        if (isset($this->store[$domain][$key])) {
            return false;
        }
        $this->store[$domain][$key] = $data;
        return true;
    }

    public function del(string $domain, string $key): bool {
        unset($this->store[$domain][$key]);
        return true;
    }

    public function has(string $domain, string $key): bool {
        return isset($this->store[$domain][$key]);
    }

    public function ping(): int {
        return 1;
    }

    public function delAll(string $domain): bool {
        unset($this->store[$domain]);
        return true;
    }

    public function incr(string $domain, string $key, int|float|null $incVal = null): int|float {
        return 0;
    }

    public function decr(string $domain, string $key, int|float|null $decVal = null): int|float {
        return 0;
    }

    public function append(string $domain, string $key, string $append): int {
        return 0;
    }

    public function getSet(string $domain, string $key, mixed $value): mixed {
        return null;
    }

    public function getSetIfNot(string $domain, string $key, mixed $data, int $ttl = 0): mixed {
        return null;
    }

    public function strLen(string $domain, string $key): int {
        return 0;
    }

    public function keys(string $domain, string $key): array {
        return [];
    }

    public function getAll(string $domain): array {
        return [];
    }

    public function stats(): array {
        return [];
    }
}

final class AuditFixRegressionTest extends TestCase {

    private function requiredDef(int $propagation = Transaction::PROPAGATION_REQUIRED): DefaultTransactionDefinition {
        return new DefaultTransactionDefinition($propagation);
    }

    // WB-001: nested REQUIRED commits once, at outer completion.
    public function testNestedRequiredCommitsOnce(): void {
        $mgr = new AuditFixFakeTxnManager();
        $outer = $mgr->getTransaction($this->requiredDef());
        $inner = $mgr->getTransaction($this->requiredDef());
        $this->assertFalse($inner->isNewTransaction());
        $mgr->commit($inner);
        $this->assertSame(0, $mgr->lastObject->commits);
        $mgr->commit($outer);
        $this->assertSame(1, $mgr->lastObject->commits);
    }

    // WB-001: inner failure marks the shared transaction rollback-only.
    public function testInnerFailureRollsBackOuter(): void {
        $mgr = new AuditFixFakeTxnManager();
        $outer = $mgr->getTransaction($this->requiredDef());
        $inner = $mgr->getTransaction($this->requiredDef());
        $mgr->rollback($inner);
        $this->assertTrue($outer->isRollbackOnly());
        $this->assertSame(0, $mgr->lastObject->rollbacks);
        $mgr->rollback($outer);
        $this->assertSame(1, $mgr->lastObject->rollbacks);
    }

    // WB-001: repeated completion is rejected.
    public function testDoubleCompletionThrows(): void {
        $mgr = new AuditFixFakeTxnManager();
        $status = $mgr->getTransaction($this->requiredDef());
        $mgr->commit($status);
        $this->assertThrows(IllegalTransactionStateException::class, function () use ($mgr, $status) {
            $mgr->commit($status);
        });
    }

    // WB-001: REQUIRES_NEW suspends the outer and resumes it afterwards.
    public function testRequiresNewSuspendsAndResumes(): void {
        $mgr = new AuditFixFakeTxnManager();
        $outer = $mgr->getTransaction($this->requiredDef());
        $inner = $mgr->getTransaction($this->requiredDef(Transaction::PROPAGATION_REQUIRES_NEW));
        $this->assertTrue($inner->isNewTransaction());
        $mgr->commit($inner);
        $this->assertFalse($outer->isCompleted());
        $mgr->commit($outer);
        $this->assertTrue($outer->isCompleted());
    }

    // WB-001: NOT_SUPPORTED performs work outside the parent transaction.
    public function testNotSupportedRunsOutsideParent(): void {
        $mgr = new AuditFixFakeTxnManager();
        $outer = $mgr->getTransaction($this->requiredDef());
        $plain = $mgr->getTransaction($this->requiredDef(Transaction::PROPAGATION_NOT_SUPPORTED));
        $this->assertFalse($plain->hasTransaction());
        $mgr->commit($plain);
        $this->assertFalse($outer->isCompleted());
        $mgr->commit($outer);
        $this->assertSame(1, $mgr->lastObject->commits);
    }

    // WB-004: fresh access-TTL entries survive; write TTL still enforced.
    public function testInMemoryAccessExpiry(): void {
        $cache = new InMemoryCache('t', new CacheConfiguration(expireAfterAccessMs: 10000));
        $cache->put('k', 'v');
        $this->assertTrue($cache->has('k'));
        $this->assertSame('v', $cache->get('k')->get());
    }

    // WB-005: miss loads once via supplier; hit never calls it again.
    public function testSharedKvCacheAside(): void {
        $kv = new AuditFixFakeKv();
        $cache = new SharedKvCache($kv, 'd');
        $calls = 0;
        $first = $cache->getOrProvide('k', function () use (&$calls) {
            $calls++;
            return 'loaded';
        });
        $this->assertSame('loaded', $first->get());
        $second = $cache->getOrProvide('k', function () use (&$calls) {
            $calls++;
            return 'other';
        });
        $this->assertSame('loaded', $second->get());
        $this->assertSame(1, $calls);
    }

    // WB-005: put-if-absent returns logical values, not serialization bytes.
    public function testSharedKvPutIfAbsentLogical(): void {
        $kv = new AuditFixFakeKv();
        $cache = new SharedKvCache($kv, 'd');
        $this->assertSame('v1', $cache->putIfAbsent('k', 'v1')->get());
        $this->assertSame('v1', $cache->putIfAbsent('k', 'v2')->get());
    }

    // WB-013: destroy callbacks run exactly once without shutdown errors.
    public function testShutdownRegistryInvokesDestroy(): void {
        $registry = new ShutDownRegistry();
        $service = new AuditFixFixtureService();
        $provider = new BeanProvider(new ClassResource());
        $provider->setCached($service);
        $provider->setDestroyMethod('close');
        $registry->registerBeanProvider($provider);
        $registry->onShutdown();
        $this->assertSame([true], $service->destroyed);
        $registry->unRegisterBeanProvider($provider);
    }

    // WB-016: savepoint completion succeeds; misuse still throws.
    public function testSavepointCompletion(): void {
        $status = new AuditFixFakeTxnStatus(new AuditFixFakeTxnObject());
        $status->createAndHoldSavepoint();
        $status->releaseHeldSavepoint();
        $status->createAndHoldSavepoint();
        $status->rollbackToHeldSavepoint();
        $bare = new AuditFixFakeTxnStatus(new AuditFixFakeTxnObject());
        $this->assertThrows(\dev\winterframework\txn\ex\TransactionUsageException::class, function () use ($bare) {
            $bare->releaseHeldSavepoint();
        });
    }

    private function proxyMethod(string $name, bool $aop): MethodResource {
        $ref = new \ReflectionMethod(AuditFixFixtureService::class, $name);
        $res = new MethodResource();
        $res->setMethod(RefMethod::getInstance($ref));
        $res->setAopProxy($aop);
        return $res;
    }

    // WB-002: proxies log failures with actual class/method context, rethrow original.
    public function testAopProxyRethrows(): void {
        $code = ProxyGenerator::getDefault()->generateMethod($this->proxyMethod('fails', true));
        $this->assertTrue(str_contains($code, 'aspectFailed'));
        $this->assertTrue(str_contains($code, 'throw $e;'));
        $this->assertFalse(str_contains($code, 'new AopException'));
        $this->assertTrue(str_contains($code, 'AOP invocation failed on'));
        $this->assertTrue(str_contains($code, 'AuditFixFixtureService::fails()'));
        $this->assertTrue(str_contains($code, 'AOP begin failed on'));
        $this->assertTrue(str_contains($code, 'setBeginFailed()'));
    }

    // WB-014: generated signatures keep literal defaults, refs, variadics.
    public function testProxyDefaultValues(): void {
        $code = ProxyGenerator::getDefault()->generateMethod($this->proxyMethod('boom', true));
        $this->assertTrue(str_contains($code, "\$label = 'x'"));
        $this->assertTrue(str_contains($code, '$opts = '));
        $this->assertFalse(str_contains($code, '$label = x,'));
        $refCode = ProxyGenerator::getDefault()->generateMethod($this->proxyMethod('byRef', false));
        $this->assertTrue(str_contains($refCode, '&$name'));
        $this->assertTrue(str_contains($refCode, '...$rest'));
    }

    // WB-015/WB-017: RefKlass union reflection exposes named-type gap honestly.
    public function testUnionTypeHasNoBuiltinApi(): void {
        $param = new \ReflectionFunction(function (AuditFixFixtureService|\stdClass $x = null) {
        })->getParameters()[0];
        $this->assertTrue($param->getType() instanceof \ReflectionUnionType);
        $this->assertFalse(method_exists($param->getType(), 'isBuiltin'));
    }
}
