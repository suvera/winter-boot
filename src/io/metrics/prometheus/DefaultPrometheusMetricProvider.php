<?php
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

class DefaultPrometheusMetricProvider implements PrometheusMetricProvider {

    public function provide(PrometheusMetricRegistry $registry): void {

        $registry->getOrRegisterHistogram(
            'http_request_duration',
            'Http Request Duration',
            ['path', 'method'],
            [0.01, 0.1, 1, 5]
        );
    }

}