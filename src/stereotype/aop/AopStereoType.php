<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\stereotype\StereoType;

interface AopStereoType extends StereoType {

    public function isPerInstance(): bool;

    public function getAspect(): WinterAspect;

}