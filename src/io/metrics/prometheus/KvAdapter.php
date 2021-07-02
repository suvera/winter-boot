<?php
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

use dev\winterframework\io\kv\KvTemplate;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use RuntimeException;

class KvAdapter implements Adapter {
    const PROMETHEUS_PREFIX = '';

    private string $domainHistogram = 'winter-metrics-hist';
    private string $domainGauge = 'winter-metrics-gauge';
    private string $domainCounter = 'winter-metrics-count';

    public function __construct(protected KvTemplate $kvTemplate) {
    }

    /**
     * @inheritDoc
     */
    public function wipeStorage(): void {
        $this->kvTemplate->delAll($this->domainHistogram);
        $this->kvTemplate->delAll($this->domainGauge);
        $this->kvTemplate->delAll($this->domainCounter);
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function collect(): array {
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        return array_merge($metrics, $this->collectCounters());
    }

    private function histogramBucketValueKey(array $data, string|int|float $bucket): string {
        $str = implode(':', [
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            $bucket,
            'value',
        ]);

        if (self::PROMETHEUS_PREFIX) {
            $str = self::PROMETHEUS_PREFIX . ':' . $str;
        }

        return $str;
    }

    public function updateHistogram(array $data): void {
        $sumKey = $this->histogramBucketValueKey($data, 'sum');
        $isNew = $this->kvTemplate->putIfNot($this->domainHistogram, $sumKey, 0);

        if ($isNew) {
            $this->kvTemplate->put($this->domainHistogram, $this->metaKey($data), json_encode($this->metaData($data)));
        }

        // Atomically increment the sum
        $this->kvTemplate->incr(
            $this->domainHistogram,
            $sumKey,
            $data['value']
        );

        // Figure out in which bucket the observation belongs
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        $bucketKey = $this->histogramBucketValueKey($data, $bucketToIncrease);
        $this->kvTemplate->incr($this->domainHistogram, $bucketKey);
    }

    public function updateGauge(array $data): void {
        $valueKey = $this->valueKey($data);
        if ($data['command'] === Adapter::COMMAND_SET) {
            $this->kvTemplate->put($this->domainGauge, $valueKey, $data['value']);
            $this->kvTemplate->put($this->domainGauge, $this->metaKey($data), json_encode($this->metaData($data)));
        } else {
            $isNew = $this->kvTemplate->putIfNot($this->domainGauge, $valueKey, 0);

            if ($isNew) {
                $this->kvTemplate->put($this->domainGauge, $this->metaKey($data), json_encode($this->metaData($data)));
            }

            $this->kvTemplate->incr(
                $this->domainGauge,
                $valueKey,
                $data['value']
            );
        }
    }

    public function updateCounter(array $data): void {
        $valueKey = $this->valueKey($data);

        $valKey = $this->valueKey($data);
        $isNew = $this->kvTemplate->putIfNot($this->domainCounter, $valKey, 0);

        if ($isNew) {
            $this->kvTemplate->put($this->domainCounter, $this->metaKey($data), json_encode($this->metaData($data)));
        }

        $this->kvTemplate->incr(
            $this->domainCounter,
            $valueKey,
            $data['value']
        );
    }

    private function metaKey(array $data): string {
        $str = implode(':', [$data['type'], $data['name'], 'meta']);
        if (self::PROMETHEUS_PREFIX) {
            $str = self::PROMETHEUS_PREFIX . ':' . $str;
        }

        return $str;
    }

    private function valueKey(array $data): string {
        $str = implode(':', [
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value',
        ]);

        if (self::PROMETHEUS_PREFIX) {
            $str = self::PROMETHEUS_PREFIX . ':' . $str;
        }

        return $str;
    }

    private function metaData(array $data): array {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectCounters(): array {
        $counters = [];

        $allCounters = $this->kvTemplate->getAll($this->domainCounter);

        foreach ($allCounters as $key => $counter) {
            if (!preg_match('/^counter:.*:meta/', $key)) {
                continue;
            }
            $metaData = json_decode($counter, true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];

            foreach ($allCounters as $key2 => $value) {
                if (!preg_match('/^counter:' . $metaData['name'] . ':.*:value/', $key2)) {
                    continue;
                }
                $parts = explode(':', $key2);
                $labelValues = $parts[2];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $value,
                ];
            }
            $this->sortSamples($data['samples']);
            $counters[] = new MetricFamilySamples($data);
        }
        return $counters;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectGauges(): array {
        $gauges = [];

        $allGauges = $this->kvTemplate->getAll($this->domainGauge);
        foreach ($allGauges as $key => $gauge) {

            if (!preg_match('/^gauge:.*:meta/', $key)) {
                continue;
            }
            $metaData = json_decode($gauge, true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];

            foreach ($allGauges as $key2 => $value) {

                if (!preg_match('/^gauge:' . $metaData['name'] . ':.*:value/', $key2)) {
                    continue;
                }
                $parts = explode(':', $key2);
                $labelValues = $parts[2];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $value,
                ];
            }

            $this->sortSamples($data['samples']);
            $gauges[] = new MetricFamilySamples($data);
        }
        return $gauges;
    }

    /**
     * @return MetricFamilySamples[]
     * @noinspection DuplicatedCode
     */
    private function collectHistograms(): array {
        $histograms = [];

        $allHists = $this->kvTemplate->getAll($this->domainHistogram);
        foreach ($allHists as $key => $histogram) {
            if (!preg_match('/^histogram:.*:meta/', $key)) {
                continue;
            }
            $metaData = json_decode($histogram, true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'] ?? '',
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'] ?? [],
                'buckets' => $metaData['buckets'] ?? [],
            ];

            // Add the Inf bucket so we can compute it later on
            $data['buckets'][] = '+Inf';

            $histogramBuckets = [];
            foreach ($allHists as $key2 => $value) {
                if (!preg_match('/^histogram:' . $metaData['name'] . ':.*:value/', $key2)) {
                    continue;
                }

                $parts = explode(':', $key2);
                $labelValues = $parts[2];
                $bucket = $parts[3];
                // Key by labelValues
                $histogramBuckets[$labelValues][$bucket] = $value;
            }

            // Compute all buckets
            $labels = array_keys($histogramBuckets);
            sort($labels);
            foreach ($labels as $labelValues) {
                $acc = 0;
                $decodedLabelValues = $this->decodeLabelValues($labelValues);
                foreach ($data['buckets'] as $bucket) {
                    $bucket = (string)$bucket;
                    if (!isset($histogramBuckets[$labelValues][$bucket])) {
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $histogramBuckets[$labelValues][$bucket];
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_' . 'bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $histogramBuckets[$labelValues]['sum'],
                ];
            }
            $histograms[] = new MetricFamilySamples($data);
        }

        return $histograms;
    }

    private function sortSamples(array &$samples): void {
        usort($samples, function ($a, $b): int {
            return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
        });
    }

    private function encodeLabelValues(array $values): string {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    private function decodeLabelValues(string $values): array {
        $json = base64_decode($values, true);
        if (false === $json) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if (false === $decodedValues) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $decodedValues;
    }
}