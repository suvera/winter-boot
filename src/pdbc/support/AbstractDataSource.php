<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\type\TypeAssert;

abstract class AbstractDataSource implements DataSource {
    protected int $timeout = 0;

    public function __construct(
        protected DataSourceConfig $config
    ) {
    }

    public function getLoginTimeout(): int {
        return $this->timeout;
    }

    public function setLoginTimeout(int $timeoutSecs): void {
        TypeAssert::positiveInteger($timeoutSecs);
        $this->timeout = $timeoutSecs;
    }


}