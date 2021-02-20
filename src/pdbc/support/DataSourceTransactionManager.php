<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\txn\support\AbstractPlatformTransactionManager;

abstract class DataSourceTransactionManager extends AbstractPlatformTransactionManager {
    public function __construct(
        protected DataSource $dataSource
    ) {
        parent::__construct();
    }

    public function getDataSource(): DataSource {
        return $this->dataSource;
    }
    
}