<?php
/** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\log\Wlf4p;
use Prometheus\Collector;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Throwable;

/**
 * @method Gauge registerGauge(string $name, string $help, array $labels = [])
 * @method Gauge getGauge(string $name)
 * @method Gauge getOrRegisterGauge(string $name, string $help, array $labels = [])
 * @method Counter registerCounter(string $name, string $help, array $labels = [])
 * @method Counter getCounter(string $name)
 * @method Counter getOrRegisterCounter(string $name, string $help, array $labels = [])
 * @method Histogram registerHistogram(string $name, string $help, array $labels = [], array $buckets = null)
 * @method Histogram getHistogram(string $name)
 * @method Histogram getOrRegisterHistogram(string $name, string $help, array $labels = [], array $buckets = null)
 */
class PrometheusMetricRegistry {
    use Wlf4p;

    protected CollectorRegistry $registry;
    protected array $metrics = [];

    public function __construct(
        protected ApplicationContext $ctx,
        protected string $adapterBean,
        protected string $adapterClass,
        protected string $providerClass
    ) {
    }

    protected function getRegistry(): void {
        if ($this->adapterBean) {
            $adapter = $this->ctx->beanByName($this->adapterBean);
        } else if ($this->adapterClass === InMemory::class
            || $this->adapterClass === APC::class
            || $this->adapterClass === NoAdapter::class
        ) {
            $cls = $this->adapterClass;
            $adapter = ReflectionUtil::createAutoWiredObject(
                $this->ctx,
                new RefKlass($cls)
            );
        } else {
            $adapter = $this->ctx->beanByClass($this->adapterClass);
        }
        $this->registry = new CollectorRegistry($adapter, false);
        $providerClass = $this->providerClass;
        if (is_a($providerClass, PrometheusMetricProvider::class, true)) {
            $provider = ReflectionUtil::createAutoWiredObject(
                $this->ctx,
                new RefKlass($providerClass)
            );
            $provider->provide($this);
        }
    }

    public function __call(string $name, array $arguments): mixed {
        if (!isset($this->registry)) {
            $this->getRegistry();
        }

        array_unshift($arguments, "");
        $metric = $this->registry->$name(...$arguments);

        if (isset($metric) && $metric instanceof Collector) {
            $this->metrics[$metric->getName()] = $metric;
        }
        return $metric;
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function getMetricFamilySamples(): array {
        if (!isset($this->registry)) {
            $this->getRegistry();
        }
        try {
            return $this->registry->getMetricFamilySamples();
        } catch (Throwable $e) {
            self::logException($e);
        }
        return [];
    }

    public function getFormatted(): string {
        if (!isset($this->registry)) {
            $this->getRegistry();
        }
        $renderer = new RenderTextFormat();
        try {
            return $renderer->render($this->registry->getMetricFamilySamples());
        } catch (Throwable $e) {
            self::logException($e);
        }
        return '';
    }

    public function observe(string $name, float $value, array $labels = []): void {
        if (!isset($this->metrics[$name])) {
            return;
        }
        $this->metrics[$name]->observe($value, $labels);
    }

    public function incr(string $name, array $labels = []): void {
        if (!isset($this->metrics[$name])) {
            return;
        }
        $this->metrics[$name]->incBy(1, $labels);
    }

    public function incrBy(string $name, int|float $value, array $labels = []): void {
        if (!isset($this->metrics[$name])) {
            return;
        }
        $this->metrics[$name]->incBy($value, $labels);
    }

    public function decr(string $name, array $labels = []): void {
        if (!isset($this->metrics[$name])) {
            return;
        }
        $this->metrics[$name]->decBy(1, $labels);
    }

    public function decrBy(string $name, int|float $value, array $labels = []): void {
        if (!isset($this->metrics[$name])) {
            return;
        }
        $this->metrics[$name]->decBy($value, $labels);
    }

    public function startTimer(string $name): Timer {
        return new Timer($name, $this);
    }

}