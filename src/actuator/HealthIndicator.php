<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

interface HealthIndicator {

    public function health(): Health;
}