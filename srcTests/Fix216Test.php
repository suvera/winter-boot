<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/McpInvokerTest.php';

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\metrics\prometheus\DefaultPrometheusMetricProvider;
use dev\winterframework\io\metrics\prometheus\NoAdapter;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\io\stream\BufferedHttpOutputStream;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\txn\aop\TransactionalAspect;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\stereotype\Transactional;
use dev\winterframework\util\log\LoggerManager;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\InternalHttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Value;
use dev\winterframework\type\TypeCast;
use ReflectionProperty;
use winterBootTests\Support\TestCase;

final class Fix216ValueCtx extends WinterApplicationContextBuilder {
    public function __construct(private array $props = []) {
    }

    public function getProperty(string $name, mixed $default = null): mixed {
        return $this->props[$name] ?? $default;
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

final class Fix216ValueBean {
    public bool $enabled = true;
    public int $port = 0;
    public float $ratio = 0.0;
    public string $name = '';
    public ?int $optional = 5;
}

#[RestController]
final class Fix216Controller {
    #[GetMapping(path: '/fix216/missing')]
    public function missing(): array {
        throw new HttpRestException(HttpStatus::$NOT_FOUND, 'order 7 of alice@example.com not found');
    }

    #[GetMapping(path: '/fix216/boom')]
    public function boom(): array {
        throw new \RuntimeException('database down');
    }

    #[GetMapping(path: '/fix216/list')]
    public function list(#[RequestParam] int $limit): array {
        return ['limit' => $limit];
    }

    #[Transactional]
    public function txn(): void {
    }
}

/**
 * 2.1.6 fixes.
 */
final class Fix216Test extends TestCase {

    private function inject(string $property, string $key, array $props, mixed $default = null): Fix216ValueBean {
        $bean = new Fix216ValueBean();
        $value = new Value('${' . $key . '}', $default);
        $value->init(RefProperty::getInstance(new ReflectionProperty(Fix216ValueBean::class, $property)));
        ReflectionUtil::performAutoValue(new Fix216ValueCtx($props), $value, $bean);
        return $bean;
    }

    // ---------------------------------------------- #[Value] casts by meaning

    public function testValueReadsFalseStringsAsFalse(): void {
        foreach (['false', 'FALSE', ' no ', 'off', '0', ''] as $raw) {
            $this->assertFalse($this->inject('enabled', 'f', ['f' => $raw])->enabled, var_export($raw, true));
        }
        foreach (['true', 'Yes', 'on', '1'] as $raw) {
            $this->assertTrue($this->inject('enabled', 'f', ['f' => $raw])->enabled, var_export($raw, true));
        }
        $this->assertFalse($this->inject('enabled', 'f', ['f' => false])->enabled, 'native bool from yml');
    }

    public function testValueDefaultIsCastTheSameWay(): void {
        $this->assertFalse($this->inject('enabled', 'missing', [], 'false')->enabled);
        $this->assertSame(8080, $this->inject('port', 'missing', [], '8080')->port);
    }

    public function testValueNumbersAreStrict(): void {
        $this->assertSame(8080, $this->inject('port', 'p', ['p' => '8080'])->port);
        $this->assertSame(7, $this->inject('port', 'p', ['p' => ' 007 '])->port);
        $this->assertSame(-3, $this->inject('port', 'p', ['p' => '-3'])->port);
        $this->assertSame(0.25, $this->inject('ratio', 'r', ['r' => '0.25'])->ratio);
        $this->assertSame('42', $this->inject('name', 'n', ['n' => 42])->name);
        $this->assertSame('null', $this->inject('name', 'n', ['n' => 'null'])->name, 'literal for strings');
        $this->assertNull($this->inject('optional', 'o', ['o' => 'null'])->optional);
        $this->assertNull($this->inject('optional', 'o', ['o' => ' NULL '])->optional, 'any case, trimmed');
        $this->assertSame(5, $this->inject('optional', 'o', ['o' => null])->optional, 'real null: property default');
    }

    public function testValueRejectsWhatDoesNotFitWithoutEchoingIt(): void {
        foreach ([
            ['enabled', 'S3cr3t-maybe'],
            ['port', '12abc'],
            ['port', '99999999999999999999'],
            ['ratio', 'abc'],
        ] as [$prop, $raw]) {
            try {
                $this->inject($prop, 'k', ['k' => $raw]);
                $this->assertTrue(false, $prop . ' accepted ' . $raw);
            } catch (WinterException $e) {
                $this->assertFalse(str_contains($e->getMessage(), $raw), 'value echoed: ' . $e->getMessage());
                $this->assertTrue(str_contains($e->getMessage(), '${k}'), $e->getMessage());
            }
        }
    }

    public function testParseConfigValueLeavesOtherTypesAlone(): void {
        $this->assertSame(['a'], TypeCast::parseConfigValue('array', ['a']));
        $this->assertSame('x', TypeCast::parseConfigValue('mixed', 'x'));
        $this->assertSame(true, TypeCast::parseConfigValue('bool', true));
    }

    public function testValueErrorSaysWhereToFix(): void {
        try {
            $this->inject('port', 'server.port', ['server.port' => 'x']);
            $this->assertTrue(false, 'accepted');
        } catch (WinterException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Fix216ValueBean::$port'), $e->getMessage());
            $this->assertTrue(str_contains($e->getMessage(), 'fix "server.port" in application.yml'), $e->getMessage());
        }
        try {
            $this->inject('port', 'missing', [], 'abc');
            $this->assertTrue(false, 'accepted');
        } catch (WinterException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'fix the defaultValue'), $e->getMessage());
        }
    }

    // ------------------------------------------------- dispatcher log levels

    /** @return array{0: int, 1: array} status, log records (level name, message) */
    private function dispatch(string $uri, array $query = []): array {
        $renderer = new DefaultResponseRenderer();
        $error = new DefaultErrorController();
        (new ReflectionProperty(DefaultErrorController::class, 'renderer'))->setValue($error, $renderer);
        $appCtx = new McpTestCtx(
            [ResponseRenderer::class => $renderer, Fix216Controller::class => new Fix216Controller()],
            ['errorController' => $error]
        );
        $appCtx->byClass[PrometheusMetricRegistry::class] = new PrometheusMetricRegistry(
            $appCtx, '', NoAdapter::class, DefaultPrometheusMetricProvider::class
        );
        $ctxData = new ApplicationContextData();
        $ctxData->setPropertyContext(new McpTestProps());
        $ctxData->setInterceptorRegistry(new InterceptorRegistry());
        $ctxData->setAopRegistry(new AopInterceptorRegistry($ctxData, $appCtx));
        $mappings = new McpTestMappings();
        foreach (['missing', 'boom', 'list'] as $m) {
            $ref = new \ReflectionMethod(Fix216Controller::class, $m);
            $mapping = $ref->getAttributes(GetMapping::class)[0]->newInstance();
            $mapping->init(RefMethod::getInstance($ref));
            $mappings->put($mapping);
        }
        $dispatcher = new DispatcherServlet($mappings, $ctxData, $appCtx);

        $request = new InternalHttpRequest(new McpStubRequest(), 'GET', $uri, $query);
        $response = new ResponseEntity();
        $response->setOutputStream(new BufferedHttpOutputStream());

        $handler = new TestHandler();
        $logger = LoggerManager::getLogger();
        $logger->pushHandler($handler);
        try {
            $dispatcher->dispatch($request, $response);
        } finally {
            $logger->popHandler();
        }
        $records = array_map(
            fn($r) => [$r['level_name'] ?? Logger::getLevelName($r['level']), (string)$r['message']],
            $handler->getRecords()
        );
        return [$response->getStatus()->getValue(), $records];
    }

    private static function levels(array $records): array {
        return array_column($records, 0);
    }

    public function testClientHttpRestExceptionIsInfoWithoutTraceOrMessage(): void {
        [$status, $records] = $this->dispatch('/fix216/missing');
        $this->assertSame(404, $status);
        $this->assertFalse(in_array('ERROR', self::levels($records), true), json_encode($records));
        $info = implode("\n", array_column($records, 1));
        $this->assertTrue(str_contains($info, 'answered 404'), $info);
        $this->assertFalse(str_contains($info, 'alice@example.com'), 'exception message logged');
        $this->assertFalse(str_contains($info, ' on File '), 'stack trace logged');
    }

    public function testServerErrorStillLoggedAtError(): void {
        [$status, $records] = $this->dispatch('/fix216/boom');
        $this->assertSame(500, $status);
        $this->assertTrue(in_array('ERROR', self::levels($records), true), json_encode($records));
    }

    public function testBadParameterIsDebugWithReadableMessage(): void {
        [$status, $records] = $this->dispatch('/fix216/list', ['limit' => 'abc']);
        $this->assertSame(400, $status);
        $this->assertFalse(in_array('ERROR', self::levels($records), true), json_encode($records));
        $debug = implode("\n", array_column(array_filter($records, fn($r) => $r[0] === 'DEBUG'), 1));
        $this->assertTrue(str_contains($debug, 'Parameter "limit": '), $debug);
        $this->assertTrue(str_contains($debug, 'Cannot assign a value of type string to type "int"'), $debug);
        $this->assertFalse(str_contains($debug, 'abc'), 'value logged');
    }

    public function testUnknownUriIsInfo(): void {
        [$status, $records] = $this->dispatch('/fix216/nope');
        $this->assertSame(404, $status);
        $this->assertFalse(in_array('ERROR', self::levels($records), true), json_encode($records));
    }

    // ------------------------------------------- transaction aspect logging

    /** @return array log records (level name, message) */
    private function runTxn(?\Throwable $failure): array {
        $file = tempnam(sys_get_temp_dir(), 'wb216-') . '.sqlite';
        $config = new DataSourceConfig();
        $config->setName('fix216');
        $config->setUrl('sqlite:' . $file);
        $mgr = new PdoTransactionManager(new PdoDataSource($config));
        $appCtx = new McpTestCtx([PlatformTransactionManager::class => $mgr]);

        $method = RefMethod::getInstance(new \ReflectionMethod(Fix216Controller::class, 'txn'));
        $stereo = new Transactional();
        $stereo->init($method);
        $ctx = new AopContext($stereo, $method, $appCtx);
        $exCtx = new AopExecutionContext(new Fix216Controller(), []);
        $aspect = new TransactionalAspect();

        $handler = new TestHandler();
        $logger = LoggerManager::getLogger();
        $logger->pushHandler($handler);
        try {
            $aspect->begin($ctx, $exCtx);
            if ($failure === null) {
                $aspect->commit($ctx, $exCtx, null);
            } else {
                $aspect->failed($ctx, $exCtx, $failure);
            }
        } finally {
            $logger->popHandler();
            @unlink($file);
        }
        return array_map(
            fn($r) => [$r['level_name'] ?? Logger::getLevelName($r['level']), (string)$r['message']],
            $handler->getRecords()
        );
    }

    public function testTransactionPerCallMessagesAreDebug(): void {
        $records = $this->runTxn(null);
        $this->assertTrue(count($records) > 0, 'nothing logged');
        foreach ($records as [$level, $msg]) {
            if (str_contains($msg, 'Transaction started') || str_contains($msg, 'Committing transaction')) {
                $this->assertSame('DEBUG', $level, $msg);
            }
        }
    }

    public function testFailedTransactionLogsOneLineWithoutTrace(): void {
        $records = $this->runTxn(new \RuntimeException('boom with details'));
        $this->assertFalse(in_array('ERROR', self::levels($records), true), json_encode($records));
        $lines = array_values(array_filter($records, fn($r) => str_contains($r[1], 'Rolling back transaction')));
        $this->assertSame(1, count($lines), json_encode($records));
        $this->assertSame('INFO', $lines[0][0]);
        $this->assertTrue(str_contains($lines[0][1], 'RuntimeException'), $lines[0][1]);
        $this->assertFalse(str_contains($lines[0][1], ' on File '), 'stack trace logged');
    }
}
