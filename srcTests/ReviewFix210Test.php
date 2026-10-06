<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\pdbc\Connection;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\web\client\DefaultRestClientTransport;
use dev\winterframework\web\client\RestTemplate;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\session\SessionManager;
use dev\winterframework\web\session\SessionOptions;
use winterBootTests\Support\TestCase;

/** A custom DataSource that cannot hand out isolated connections. */
final class ReviewFixPlainDataSource implements DataSource {
    public function __construct(private PdoDataSource $inner) {
    }

    public function getConnection(): Connection {
        return $this->inner->getConnection();
    }

    public function getLoginTimeout(): int {
        return $this->inner->getLoginTimeout();
    }

    public function setLoginTimeout(int $timeoutSecs): void {
        $this->inner->setLoginTimeout($timeoutSecs);
    }

    public function checkIdleConnection(): void {
        $this->inner->checkIdleConnection();
    }
}

final class ReviewFixMemoryStore implements \SessionHandlerInterface {
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

final class ReviewFixCookieRequest extends HttpRequest {
    public function __construct(private array $stubCookies = []) {
    }

    public function getCookie(string $name): ?string {
        return $this->stubCookies[$name] ?? null;
    }
}

#[RestController]
class ReviewFixRouteController {
    #[GetMapping(path: '/rf-users/{id}')]
    public function user(#[PathVariable(name: 'id')] string $id): void {
    }

    #[GetMapping(path: '/rf-users/me')]
    public function me(): void {
    }
}

/**
 * Regressions found reviewing 2.1.0 against master; each test fails on the
 * reviewed HEAD and passes with the fix.
 */
final class ReviewFix210Test extends TestCase {

    private array $tmpFiles = [];

    public function __destruct() {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    private function dataSource(): PdoDataSource {
        $file = tempnam(sys_get_temp_dir(), 'wbrf-') . '.sqlite';
        $this->tmpFiles[] = $file;
        $config = new DataSourceConfig();
        $config->setName('reviewfix');
        $config->setUrl('sqlite:' . $file);
        return new PdoDataSource($config);
    }

    // A custom DataSource without IsolatedConnectionProvider keeps the
    // historic shared-connection behaviour instead of failing every call.
    public function testNotSupportedOnPlainDataSourceStillWorks(): void {
        $ds = new ReviewFixPlainDataSource($this->dataSource());
        $mgr = new PdoTransactionManager($ds);

        $outer = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        $none = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_NOT_SUPPORTED));
        $mgr->commit($none);
        $mgr->commit($outer);
        $this->assertTrue(true);
    }

    // HEAD under cURL must not wait for the body Content-Length announces.
    public function testCurlHeadReturnsHeaders(): void {
        if (!function_exists('curl_init')) {
            return;
        }
        $router = tempnam(sys_get_temp_dir(), 'wbrf-router-') . '.php';
        $this->tmpFiles[] = $router;
        file_put_contents($router, '<?php header("Content-Length: 1000"); header("X-Probe: yes");'
            . ' if ($_SERVER["REQUEST_METHOD"] !== "HEAD") { echo str_repeat("x", 1000); }');
        $port = 20000 + random_int(0, 20000);
        $proc = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        try {
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline && !@fsockopen('127.0.0.1', $port, $en, $es, 0.1)) {
                usleep(50000);
            }
            $template = new RestTemplate(new DefaultRestClientTransport());
            $template->setTimeout(3);
            $headers = $template->headForHeaders('http://127.0.0.1:' . $port . '/');
            $this->assertSame(['yes'], $headers->get('X-Probe'));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    // A custom error handler that returns gets the raw body, never a decode.
    public function testErrorHandlerReturningGetsRawBody(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(502, '<html>bad gateway</html>');
        $template = new RestTemplate($transport);
        $seen = [];
        $template->setErrorHandler(function (string $method, string $url, int $status) use (&$seen): void {
            $seen[] = $status;
        });
        $entity = $template->getForEntity('http://api.local/flaky');
        $this->assertSame([502], $seen);
        $this->assertSame(502, $entity->getStatus()->getValue());
        $this->assertSame('<html>bad gateway</html>', $entity->getBody());
    }

    // regenerateId() then destroy() must remove the pre-rotation row too.
    public function testDestroyAfterRegenerateRemovesOldRow(): void {
        $mgr = new SessionManager();
        $store = new ReviewFixMemoryStore();
        $store->rows['knownid123'] = serialize(['u' => 1]);
        $opts = new SessionOptions();
        $session = $mgr->open(new ReviewFixCookieRequest([$opts->name => 'knownid123']), $store, $opts);
        $this->assertSame('knownid123', $session->getId());

        $session->regenerateId();
        $session->destroy();
        $mgr->commit($session, new ResponseEntity(), $store, $opts);
        $this->assertSame([], $store->rows, 'every session row must be gone after logout');
    }

    // The dispatcher hands missingParameters() the RefMethod wrapper; a
    // TypeError there turned every routed request into a 500.
    public function testMissingParametersAcceptsRefMethod(): void {
        $ref = RefMethod::getInstance(new \ReflectionMethod(ReviewFixRouteController::class, 'user'));
        $this->assertSame([], DispatcherServlet::missingParameters($ref, ['id' => '7']));
        $this->assertSame(['id'], DispatcherServlet::missingParameters($ref, []));
    }

    // Swoole's on() is case-insensitive and keeps one handler per event: a
    // module's 'WorkerStart' must not replace the framework's 'workerStart'
    // (which registers worker pids for shutdown), and framework callbacks
    // registered with $first run before a module's never-returning loop.
    public function testEventCallbacksGroupCaseInsensitively(): void {
        $server = (new \ReflectionClass(WinterServer::class))->newInstanceWithoutConstructor();
        $calls = [];
        $server->addEventCallback('WorkerStart', function () use (&$calls) { $calls[] = 'module'; });
        $server->addEventCallback('workerStart', function () use (&$calls) { $calls[] = 'framework'; }, true);
        $callbacks = (new \ReflectionProperty(WinterServer::class, 'eventCallbacks'))->getValue($server);
        $this->assertSame(['workerstart'], array_keys($callbacks));
        foreach ($callbacks['workerstart'] as $cb) {
            $cb();
        }
        $this->assertSame(['framework', 'module'], $calls);
    }

    // Deleting a concrete route keeps the template route it also matches.
    public function testRouteDeleteKeepsTemplateRoute(): void {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        foreach (['user', 'me'] as $name) {
            $ref = RefMethod::getInstance(new \ReflectionMethod(ReviewFixRouteController::class, $name));
            foreach ($ref->getAttributes(GetMapping::class) as $a) {
                $inst = $a->newInstance();
                $inst->init($ref);
                $reg->put($inst);
            }
        }
        $reg->delete('rf-users/me');
        $this->assertTrue($reg->find('rf-users/42', 'GET') !== null, 'template route must survive');

        $reg->delete('rf-users/{id}');
        $this->assertNull($reg->find('rf-users/42', 'GET'));
    }
}
