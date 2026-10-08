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

    private bool $sharedIsolationWarned = false;

    protected function beginIsolation(): void {
        if (!($this->dataSource instanceof IsolatedConnectionProvider)) {
            // Historic behaviour for custom DataSources: the inner work runs
            // on the shared connection, so it is not isolated from the
            // suspended transaction. Kept for compatibility; warn once.
            if (!$this->sharedIsolationWarned) {
                $this->sharedIsolationWarned = true;
                self::logWarning('REQUIRES_NEW/NOT_SUPPORTED on DataSource ' . get_class($this->dataSource)
                    . ' run on the shared connection; implement ' . IsolatedConnectionProvider::class
                    . ' for a separate connection');
            }
            return;
        }
        $this->dataSource->beginIsolation();
    }

    protected function endIsolation(): void {
        if ($this->dataSource instanceof IsolatedConnectionProvider) {
            $this->dataSource->endIsolation();
        }
    }

}