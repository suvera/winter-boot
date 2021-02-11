<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\reflection\ref\RefMethod;

final class AopContext {

    public function __construct(
        public AopStereoType $stereoType,
        public RefMethod $method
    ) {
    }
}