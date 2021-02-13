<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ref\RefMethod;
use WeakMap;

final class AopContext {
    private WeakMap $cachesByOwner;

    public function __construct(
        private AopStereoType $stereoType,
        private RefMethod $method,
        private ApplicationContext $appCtx
    ) {
        $this->cachesByOwner = new WeakMap();
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

    public function getCaches(object $owner, string $op): array {
        return $this->cachesByOwner[$owner][$op] ?? [];
    }

    public function setCaches(object $owner, string $op, array $caches): void {
        if (!isset($this->cachesByOwner[$owner][$op])) {
            $this->cachesByOwner[$owner] = [];
        }
        $this->cachesByOwner[$owner][$op] = $caches;
    }


}