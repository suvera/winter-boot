<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\metrics;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

class OpenTelemetryMetrics {
    private ?MeterInterface $meter = null;
    private array $counters = [];
    private array $histograms = [];

    public function __construct(string $name = 'dev.winterframework.metrics', string $version = '1.0.0') {
        if (class_exists(Globals::class)) {
            $this->meter = Globals::meterProvider()->getMeter($name, $version);
        }
    }

    public function counter(string $name, string $description = '', string $unit = ''): ?CounterInterface {
        if (!$this->meter) {
            return null;
        }
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = $this->meter->createCounter($name, $unit, $description);
        }
        return $this->counters[$name];
    }

    public function histogram(string $name, string $description = '', string $unit = ''): ?HistogramInterface {
        if (!$this->meter) {
            return null;
        }
        if (!isset($this->histograms[$name])) {
            $this->histograms[$name] = $this->meter->createHistogram($name, $unit, $description);
        }
        return $this->histograms[$name];
    }

    public function add(string $name, int|float $value, array $attributes = []): void {
        $counter = $this->counter($name);
        if ($counter) {
            $counter->add($value, $attributes);
        }
    }

    public function record(string $name, int|float $value, array $attributes = []): void {
        $histogram = $this->histogram($name);
        if ($histogram) {
            $histogram->record($value, $attributes);
        }
    }
}
