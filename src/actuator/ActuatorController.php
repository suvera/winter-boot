<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use dev\winterframework\web\http\ResponseEntity;

interface ActuatorController {
    public function getBeans(): ResponseEntity;

    public function getConfigProps(): ResponseEntity;

    public function getEnv(): ResponseEntity;

    public function getHealth(): ResponseEntity;

    public function getInfo(): ResponseEntity;

    public function getMappings(): ResponseEntity;

    public function getPrometheus(): ResponseEntity;

    public function getScheduledTasks(): ResponseEntity;

    public function getHeapDump(): ResponseEntity;
}