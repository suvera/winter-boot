<?php
declare(strict_types=1);

namespace examples\MyApp\health;

use dev\winterframework\actuator\Health;
use dev\winterframework\actuator\HealthIndicator;
use dev\winterframework\actuator\stereotype\HealthInformer;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;

#[HealthInformer]
class DatabaseHealthIndicator implements HealthIndicator {

    #[Autowired]
    private PdbcTemplate $pdbc;

    public function health(): Health {
        $success = $this->pdbc->queryForScalar('select 1');

        return $success ? Health::up() : Health::down()->withDetail('database', 'down');
    }

}