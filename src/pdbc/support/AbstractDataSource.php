<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;

abstract class AbstractDataSource implements DataSource {
    use Wlf4p;

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

    public function checkIdleConnection(): void {
        $this->getConnection()->checkIdleConnection();
    }

}