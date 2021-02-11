<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

interface StereoType {
    public function init(object $ref): void;
}