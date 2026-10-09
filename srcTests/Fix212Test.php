<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\app\WinterApplicationRunner;
use dev\winterframework\core\app\WinterWebSwooleApplication;
use dev\winterframework\core\app\WorkerStartEvent;
use dev\winterframework\core\app\WorkerStopEvent;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\io\kv\KvClient;
use dev\winterframework\io\kv\KvConfig;
use dev\winterframework\io\kv\KvRequest;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\OnWorkerStart;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\util\UriPathPart;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\http\SwooleRequest;
use RuntimeException;
use Swoole\Coroutine;
use winterBootTests\Support\StubPropertySource;
use winterBootTests\Support\TestCase;

require_once __DIR__ . '/SwooleRequestTest.php';

#[RestController]
class DottedRouteController {
    #[GetMapping(path: '/fix212/robots.txt')]
    public function robots(): void {
    }

    #[GetMapping(path: '/fix212/assets/snow.min.js')]
    public function snow(): void {
    }

    #[GetMapping(path: '/fix212/assets/{name}')]
    public function asset(#[PathVariable(name: 'name')] string $name): void {
    }
}

#[Component]
#[OnWorkerStart]
class RecordingWorkerHook implements WorkerStartEvent, WorkerStopEvent {
    public array $seen = [];

    public function __construct(private bool $fail = false) {
    }

    public function onWorkerStart(int $workerId): void {
        $this->seen[] = "start:$workerId";
        if ($this->fail) {
            throw new RuntimeException('start failed');
        }
    }

    public function onWorkerStop(int $workerId): void {
        $this->seen[] = "stop:$workerId";
        if ($this->fail) {
            throw new RuntimeException('stop failed');
        }
    }
}

#[Component]
#[OnWorkerStart]
class NotAWorkerHook {
}

/**
 * Regressions for the 2.1.2 batch: case-insensitive request headers,
 * dotted route segments, typed "$env" server settings, the release
 * version in the banner, worker lifecycle hooks, compact JSON, and a
 * non-blocking, coroutine-safe KV/queue client.
 */
final class Fix212Test extends TestCase {

    // ---- Headers -------------------------------------------------------

    public function testHeaderLookupIsCaseInsensitive(): void {
        $h = new HttpHeaders();
        $h->add('User-Agent', 'curl/8');
        $this->assertSame('curl/8', $h->getFirst('user-agent'));
        $this->assertSame('curl/8', $h->getFirst('USER-AGENT'));
        $this->assertSame(['curl/8'], $h->get('user-agent'));
        $this->assertTrue($h->contains('user-agent'));
    }

    public function testHeaderNamesDifferingInCaseAreOneHeader(): void {
        $h = new HttpHeaders();
        $h->add('X-Trace', 'a');
        $h->add('x-trace', 'b');
        $this->assertSame(['X-Trace' => ['a', 'b']], $h->getAll());

        $h->set('X-TRACE', 'c');
        $this->assertSame(['X-TRACE' => ['c']], $h->getAll());

        $h->setIfNot('x-trace', 'd');
        $this->assertSame(['c'], $h->get('x-trace'));

        $h->remove('x-Trace');
        $this->assertSame([], $h->getAll());
        $this->assertNull($h->getFirst('X-TRACE'));
    }

    public function testHeaderMergeReplacesAcrossCase(): void {
        $a = new HttpHeaders();
        $a->add('Content-Type', 'text/plain');
        $b = new HttpHeaders();
        $b->add('content-type', 'application/json');
        $a->merge($b);
        $this->assertSame(['content-type' => ['application/json']], $a->getAll());
        $this->assertSame('application/json', $a->getContentType());
    }

    public function testSwooleRequestHeaderLookupIgnoresCase(): void {
        $raw = new FakeSwooleHttpRequest();
        $raw->get = [];
        $raw->post = [];
        $raw->cookie = [];
        $raw->server = ['request_uri' => '/'];
        $raw->header = ['user-agent' => 'Mozilla/5.0', 'x-api-key' => 'k1', 'etag' => 'W/"1"'];
        $raw->files = [];
        $req = new SwooleRequest($raw, new \Swoole\Http\Response());

        $this->assertSame('Mozilla/5.0', $req->getFirstHeader('user-agent'));
        $this->assertSame('Mozilla/5.0', $req->getFirstHeader('User-Agent'));
        $this->assertSame('k1', $req->getFirstHeader('X-API-Key'));
        $this->assertSame('W/"1"', $req->getFirstHeader(HttpHeaders::ETAG));
    }

    // ---- Routes --------------------------------------------------------

    public function testRoutePartAcceptsDots(): void {
        $this->assertSame('robots.txt', (new UriPathPart('robots.txt'))->getPart());
        $this->assertSame('snow.min.js', (new UriPathPart('snow.min.js'))->getPart());
    }

    public function testRoutePartRejectsDotOnlySegments(): void {
        $this->assertThrows(InvalidSyntaxException::class, fn() => new UriPathPart('.'));
        $this->assertThrows(InvalidSyntaxException::class, fn() => new UriPathPart('..'));
        $this->assertThrows(InvalidSyntaxException::class, fn() => new UriPathPart('a/b'));
    }

    public function testDottedRoutesResolve(): void {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        foreach (['robots', 'snow', 'asset'] as $m) {
            $ref = RefMethod::getInstance(new \ReflectionMethod(DottedRouteController::class, $m));
            foreach ($ref->getAttributes(GetMapping::class) as $a) {
                $inst = $a->newInstance();
                $inst->init($ref);
                $reg->put($inst);
            }
        }
        $owner = fn(string $p) => $reg->find($p, 'GET')?->getMapping()->getRefOwner()->getName();

        $this->assertSame('robots', $owner('fix212/robots.txt'));
        $this->assertSame('snow', $owner('fix212/assets/snow.min.js'));
        // Literal wins; other names still reach the variable route.
        $this->assertSame('asset', $owner('fix212/assets/app.css'));
        $this->assertNull($owner('fix212/robotsXtxt'));
    }

    // ---- $env server settings -----------------------------------------

    private function envServerContext(array $env): WinterPropertyContext {
        StubPropertySource::$datasets = ['env' => $env];
        return new WinterPropertyContext([__DIR__ . '/fixtures/env-server']);
    }

    public function testEnvPortBecomesInteger(): void {
        $ctx = $this->envServerContext(['HOST' => '0.0.0.0', 'PORT' => '9090', 'WORKERS' => '4']);
        $this->assertSame(['0.0.0.0', 9090], WinterServer::listenAddress($ctx));
    }

    public function testEnvSwooleArgsGetNativeTypes(): void {
        $ctx = $this->envServerContext([
            'HOST' => 'h', 'PORT' => '1', 'WORKERS' => '4', 'DAEMON' => 'false',
        ]);
        $this->assertSame(4, WinterWebSwooleApplication::coerceServerArg($ctx->get('server.swoole.worker_num')));
        $this->assertSame(false, WinterWebSwooleApplication::coerceServerArg($ctx->get('server.swoole.daemonize')));
        $this->assertSame('/tmp/swoole.log', WinterWebSwooleApplication::coerceServerArg($ctx->get('server.swoole.log_file')));
        $this->assertSame(true, WinterWebSwooleApplication::coerceServerArg('TRUE'));
        $this->assertSame(-1, WinterWebSwooleApplication::coerceServerArg('-1'));
        $this->assertSame('1.5', WinterWebSwooleApplication::coerceServerArg('1.5'));
        $this->assertSame(['a'], WinterWebSwooleApplication::coerceServerArg(['a']));
    }

    // ---- Banner version ------------------------------------------------

    public function testReleaseTagWinsOverStaleVersionFile(): void {
        $this->assertSame('2.1.0', WinterApplicationRunner::resolveBootVersion('2.1.0', '1.0.0-dev'));
        $this->assertSame('2.1.2', WinterApplicationRunner::resolveBootVersion('v2.1.2', '1.0.0-dev'));
        $this->assertSame('2.2.0-RC1', WinterApplicationRunner::resolveBootVersion('2.2.0-RC1', 'x'));
    }

    public function testDevInstallFallsBackToVersionFile(): void {
        $this->assertSame('2.1.2', WinterApplicationRunner::resolveBootVersion('dev-master', "2.1.2\n"));
        $this->assertSame('2.1.2', WinterApplicationRunner::resolveBootVersion(null, '2.1.2'));
        $this->assertSame('unknown', WinterApplicationRunner::resolveBootVersion(null, ''));
    }

    public function testVersionFileIsCurrent(): void {
        $runner = (new \ReflectionClass(WinterWebSwooleApplication::class))->newInstanceWithoutConstructor();
        $this->assertSame(
            trim((string)file_get_contents(dirname(__DIR__) . '/VERSION.txt')),
            $runner->getBootVersion()
        );
    }

    // ---- Worker lifecycle hooks ---------------------------------------

    public function testWorkerStartHooksRunInOrderAndFailLoudly(): void {
        $a = new RecordingWorkerHook();
        $b = new RecordingWorkerHook(true);
        $c = new RecordingWorkerHook();
        $beans = ['A' => $a, 'B' => $b, 'C' => $c];

        WinterWebSwooleApplication::runWorkerHooks(['A'], fn($cls) => $beans[$cls], 3, false);
        $this->assertSame(['start:3'], $a->seen);

        $this->assertThrows(RuntimeException::class, function () use ($beans) {
            WinterWebSwooleApplication::runWorkerHooks(['B', 'C'], fn($cls) => $beans[$cls], 1, false);
        });
        $this->assertSame([], $c->seen);
    }

    public function testWorkerStopHookFailureDoesNotSkipOthers(): void {
        $b = new RecordingWorkerHook(true);
        $c = new RecordingWorkerHook();
        $beans = ['B' => $b, 'C' => $c];
        WinterWebSwooleApplication::runWorkerHooks(['B', 'C'], fn($cls) => $beans[$cls], 2, true);
        $this->assertSame(['stop:2'], $b->seen);
        $this->assertSame(['stop:2'], $c->seen);
    }

    public function testOnWorkerStartRequiresInterface(): void {
        (new OnWorkerStart())->init(RefKlass::getInstance(RecordingWorkerHook::class));
        $this->assertThrows(\Throwable::class, function () {
            (new OnWorkerStart())->init(RefKlass::getInstance(NotAWorkerHook::class));
        });
    }

    // ---- JSON pretty print --------------------------------------------

    private function renderedJson(DefaultResponseRenderer $renderer): string {
        $entity = ResponseEntity::ok(['a' => 1, 'b' => [2]]);
        $check = \Closure::bind(
            fn() => $this->checkResponseContentType($entity, $entity->getOutputStream()),
            $renderer,
            DefaultResponseRenderer::class
        );
        $check();
        return $entity->getBody();
    }

    public function testJsonPrettyPrintIsDefault(): void {
        $this->assertSame(json_encode(['a' => 1, 'b' => [2]], JSON_PRETTY_PRINT),
            $this->renderedJson(new DefaultResponseRenderer()));
    }

    public function testJsonCanBeCompact(): void {
        $this->assertSame('{"a":1,"b":[2]}', $this->renderedJson(new DefaultResponseRenderer(false)));
    }

    // ---- KV client inside coroutines ----------------------------------

    public function testKvClientDoesNotBlockOrMixCoroutines(): void {
        $results = [];
        $elapsed = 0.0;
        Coroutine\run(function () use (&$results, &$elapsed) {
            $server = new Coroutine\Server('127.0.0.1', 0);
            $port = $server->port;
            Coroutine::create(function () use ($server) {
                $server->handle(function (Coroutine\Server\Connection $conn) {
                    $buf = '';
                    while (($chunk = $conn->recv(5)) !== '' && $chunk !== false) {
                        $buf .= $chunk;
                        while (($pos = strpos($buf, "\n")) !== false) {
                            $line = substr($buf, 0, $pos);
                            $buf = substr($buf, $pos + 1);
                            $req = KvRequest::jsonUnSerialize(json_decode($line, true));
                            // Every reply is delayed: a blocking client would
                            // serialize the coroutines, a shared socket would
                            // hand one coroutine another's value.
                            Coroutine::sleep(0.2);
                            $conn->send(json_encode([1, '', 'v-' . $req->getKey()]) . "\n");
                        }
                    }
                });
                $server->start();
            });

            $client = new KvClient(new KvConfig('tok', $port, '127.0.0.1', null, null, 5.0));
            $start = microtime(true);
            $wg = new Coroutine\WaitGroup();
            for ($i = 0; $i < 5; $i++) {
                $wg->add();
                Coroutine::create(function () use ($client, $i, &$results, $wg) {
                    // Two calls per coroutine: the second reuses an idle connection.
                    try {
                        $results[$i] = [$client->get('d', "k$i"), $client->get('d', "k$i-2")];
                    } catch (\Throwable $e) {
                        $results[$i] = $e->getMessage();
                    }
                    $wg->done();
                });
            }
            $wg->wait();
            $elapsed = microtime(true) - $start;
            $client->__destruct();
            $server->shutdown();
        });

        ksort($results);
        foreach ($results as $i => $pair) {
            $this->assertSame(["v-k$i", "v-k$i-2"], $pair);
        }
        $this->assertSame(5, count($results));
        // 5 coroutines x 2 sequential calls x 0.2s: ~0.4s concurrent, 2s blocking.
        $this->assertTrue($elapsed < 1.2, "coroutines ran concurrently (took {$elapsed}s)");
    }
}
