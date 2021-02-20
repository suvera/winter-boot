<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ref\RefMethod;
use WeakMap;

final class AopContext {
    private WeakMap $ctxDataByOwner;

    public function __construct(
        private AopStereoType $stereoType,
        private RefMethod $method,
        private ApplicationContext $appCtx
    ) {
        $this->ctxDataByOwner = new WeakMap();
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

    public function getCtxData(object $owner, string $op): mixed {
        return $this->ctxDataByOwner[$owner][$op] ?? [];
    }

    public function clearCtxData(object $owner, string $op): void {
        if (isset($this->ctxDataByOwner[$owner]) && isset($this->ctxDataByOwner[$owner][$op])) {
            //unset($this->ctxDataByOwner[$owner][$op]);
        };
    }

    public function setCtxData(object $owner, string $op, mixed $caches): void {
        if (!isset($this->ctxDataByOwner[$owner][$op])) {
            $this->ctxDataByOwner[$owner] = [];
        }
        $this->ctxDataByOwner[$owner][$op] = $caches;
    }
}