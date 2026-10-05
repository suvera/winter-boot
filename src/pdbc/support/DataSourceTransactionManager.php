<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\txn\ex\IllegalTransactionStateException;
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

    protected function beginIsolation(): void {
        if (!($this->dataSource instanceof IsolatedConnectionProvider)) {
            // Running on the same connection would commit or expose the
            // suspended transaction's work, so refuse instead.
            throw new IllegalTransactionStateException(
                'REQUIRES_NEW/NOT_SUPPORTED need a DataSource implementing '
                . IsolatedConnectionProvider::class
            );
        }
        $this->dataSource->beginIsolation();
    }

    protected function endIsolation(): void {
        if ($this->dataSource instanceof IsolatedConnectionProvider) {
            $this->dataSource->endIsolation();
        }
    }

}