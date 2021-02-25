<?php
declare(strict_types=1);

namespace examples\MyApp\health;

use dev\winterframework\actuator\InfoBuilder;
use dev\winterframework\actuator\InfoContributor;
use dev\winterframework\actuator\stereotype\InfoInformer;

#[InfoInformer]
class ApplicationInfoInformer implements InfoContributor {

    public function contribute(InfoBuilder $info): void {
        $info->withDetail('appName', 'ExampleMicroService')
            ->withDetail('appVersion', '1.0.0-1');

    }
}