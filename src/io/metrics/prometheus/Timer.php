<?php
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

class Timer {

    protected float $time;

    public function __construct(
        protected string $name,
        protected PrometheusMetricRegistry $registry
    ) {
        $this->time = microtime(true);
    }

    public function stop(array $labels = []): void {
        $this->registry->observe($this->name, (microtime(true) - $this->time), $labels);
    }
}