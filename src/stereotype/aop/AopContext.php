<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ref\RefMethod;

final class AopContext {

    public function __construct(
        private AopStereoType $stereoType,
        private RefMethod $method,
        private ApplicationContext $appCtx
    ) {
    }

    public function getStereoType(): AopStereoType {
        return $this->stereoType;
    }

    public function getMethod(): RefMethod {
        return $this->method;
    }

    public function getApplicationContext(): ApplicationContext {
        return $this->appCtx;
    }
}