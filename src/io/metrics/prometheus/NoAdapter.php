<?php
declare(strict_types=1);

namespace dev\winterframework\io\metrics\prometheus;

use Prometheus\Storage\Adapter;

class NoAdapter implements Adapter {
    public function collect(): array {
        return [];
    }

    public function updateHistogram(array $data): void {
        //Nothing
    }

    public function updateGauge(array $data): void {
        //Nothing
    }

    public function updateCounter(array $data): void {
        //Nothing
    }

    public function wipeStorage(): void {
        //Nothing
    }

    public function updateSummary(array $data): void {
        // TODO: Implement updateSummary() method.
    }

}