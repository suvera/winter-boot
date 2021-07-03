<?php
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

interface PrometheusMetricProvider {

    public function provide(PrometheusMetricRegistry $registry): void;
}