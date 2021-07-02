<?php
/** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

use dev\winterframework\core\context\ApplicationContext;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;

/**
 * @method array getMetricFamilySamples()
 * @method Gauge registerGauge(string $namespace, string $name, string $help, array $labels = [])
 * @method Gauge getGauge(string $namespace, string $name)
 * @method Gauge getOrRegisterGauge(string $namespace, string $name, string $help, array $labels = [])
 * @method Counter registerCounter(string $namespace, string $name, string $help, array $labels = [])
 * @method Counter getCounter(string $namespace, string $name)
 * @method Counter getOrRegisterCounter(string $namespace, string $name, string $help, array $labels = [])
 * @method Histogram registerHistogram(string $namespace, string $name, string $help, array $labels = [], array $buckets = null)
 * @method Histogram getHistogram(string $namespace, string $name)
 * @method Histogram getOrRegisterHistogram(string $namespace, string $name, string $help, array $labels = [], array $buckets = null)
 */
class PrometheusMetricRegistry {

    protected CollectorRegistry $registry;

    public function __construct(
        protected ApplicationContext $ctx,
        protected string $adapterBean,
        protected string $adapterClass
    ) {
    }

    protected function getRegistry(): void {
        if ($this->adapterBean) {
            $adapter = $this->ctx->beanByName($this->adapterBean);
        } else if ($this->adapterClass === InMemory::class || $this->adapterClass === APC::class) {
            $cls = $this->adapterClass;
            $adapter = new $cls();
        } else {
            $adapter = $this->ctx->beanByClass($this->adapterClass);
        }
        $this->registry = new CollectorRegistry($adapter, false);
    }

    public function __call(string $name, array $arguments): mixed {
        if (!isset($this->registry)) {
            $this->getRegistry();
        }
        return $this->registry->$name(...$arguments);
    }

    public function getFormatted(): string {
        if (!isset($this->registry)) {
            $this->getRegistry();
        }
        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }
}