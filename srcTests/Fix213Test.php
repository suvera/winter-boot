<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\actuator\DefaultActuatorController;
use dev\winterframework\actuator\Status;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\io\metrics\prometheus\DefaultPrometheusMetricProvider;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\web\http\ResponseEntity;
use Prometheus\Storage\InMemory;
use ReflectionClass;
use winterBootTests\Support\TestCase;

class Fix213Test extends TestCase {

    // ---- /health status code ------------------------------------------

    public function testHealthDownIsServiceUnavailable(): void {
        $this->assertSame(503, DefaultActuatorController::healthHttpStatus(Status::DOWN)->getValue());
        $this->assertSame(503, DefaultActuatorController::healthHttpStatus(Status::OUT_OF_SERVICE)->getValue());
    }

    public function testHealthUpIsOk(): void {
        $this->assertSame(200, DefaultActuatorController::healthHttpStatus(Status::UP)->getValue());
        $this->assertSame(200, DefaultActuatorController::healthHttpStatus(Status::UNKNOWN)->getValue());
    }

    // ---- Prometheus before the first scrape ----------------------------

    private function metricRegistry(): PrometheusMetricRegistry {
        $ctx = (new ReflectionClass(WinterApplicationContext::class))->newInstanceWithoutConstructor();
        return new PrometheusMetricRegistry($ctx, '', InMemory::class, DefaultPrometheusMetricProvider::class);
    }

    public function testMetricsRecordedBeforeFirstScrape(): void {
        $registry = $this->metricRegistry();
        $registry->observe('http_request_duration', 0.05, ['/x', 'GET']);
        $registry->startTimer('http_request_duration')->stop(['/x', 'GET']);

        $this->assertTrue(str_contains(
            $registry->getFormatted(),
            'http_request_duration_count{path="/x",method="GET"} 2'
        ), 'observations made before the first scrape are kept');
    }

    public function testUnknownMetricIsStillIgnored(): void {
        $registry = $this->metricRegistry();
        $registry->incr('no_such_counter');
        $registry->incrBy('no_such_counter', 2);
        $this->assertFalse(str_contains($registry->getFormatted(), 'no_such_counter'));
    }

    // ---- missing scan directory ----------------------------------------

    public function testMissingScanDirectoryIsSkipped(): void {
        $this->assertSame([], DirectoryScanner::scanForPhpClasses(
            __DIR__ . '/fixtures/does-not-exist-' . uniqid(),
            'app\\gone'
        ));
    }

    // ---- defaults -------------------------------------------------------

    public function testDefaultMaxConnectionsFitsPostgresDefaults(): void {
        $this->assertSame(10, (new DataSourceConfig())->getMaxConnections());
    }

    public function testJsonIsCompactByDefault(): void {
        $entity = ResponseEntity::ok(['a' => 1, 'b' => [2]]);
        $renderer = new DefaultResponseRenderer();
        $check = \Closure::bind(
            fn() => $this->checkResponseContentType($entity, $entity->getOutputStream()),
            $renderer,
            DefaultResponseRenderer::class
        );
        $check();
        $this->assertSame('{"a":1,"b":[2]}', $entity->getBody());
    }
}
