<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

interface InfoContributor {
    public function contribute(InfoBuilder $info): void;
}