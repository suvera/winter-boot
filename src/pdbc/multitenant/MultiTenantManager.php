<?php

declare(strict_types=1);

namespace dev\winterframework\pdbc\multitenant;

use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTemplate;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\BeansDependencyException;

class MultiTenantManager {

    /**
     * @var array<string, PdbcTemplate>
     */
    private array $pdbcTemplates = [];

    /**
     * @var array<string, PlatformTransactionManager>
     */
    private array $txnManagers = [];

    /**
     * @var array<string, PdoDataSource>
     */
    private array $dataSources = [];

    private ?TenantDataSourceProvider $tenantDataSourceProvider;

    public function __construct(
        private string $providerClassName,
        private ApplicationContext $appCtx
    ) {
    }

    /**
     * Get the tenant data source provider.
     *
     * @return TenantDataSourceProvider
     */
    public function getTenantDataSourceProvider(): TenantDataSourceProvider {
        return $this->tenantDataSourceProvider;
    }

    /**
     * Get a PdbcTemplate for the given tenant.
     * Templates are cached per tenant ID.
     *
     * @param string $tenantId
     * @return PdbcTemplate
     */
    public function getPdbcTemplate(string $tenantId): PdbcTemplate {
        if (!isset($this->pdbcTemplates[$tenantId])) {
            $ds = $this->getDataSource($tenantId);
            $this->pdbcTemplates[$tenantId] = new PdoTemplate($ds);
        }
        return $this->pdbcTemplates[$tenantId];
    }

    /**
     * Get a PlatformTransactionManager for the given tenant.
     * Transaction managers are cached per tenant ID.
     *
     * @param string $tenantId
     * @return PlatformTransactionManager
     */
    public function getTransactionManager(string $tenantId): PlatformTransactionManager {
        if (!isset($this->txnManagers[$tenantId])) {
            $ds = $this->getDataSource($tenantId);
            $this->txnManagers[$tenantId] = new PdoTransactionManager($ds);
        }
        return $this->txnManagers[$tenantId];
    }

    /**
     * Get or create a DataSource for the given tenant.
     *
     * @param string $tenantId
     * @return PdoDataSource
     */
    private function getDataSource(string $tenantId): PdoDataSource {
        if (!isset($this->dataSources[$tenantId])) {
            if ($this->tenantDataSourceProvider === null) {
                if (!$this->appCtx->hasBeanByClass($this->providerClassName)) {
                    throw new BeansDependencyException('TenantDataSourceProvider bean not found for class: ' . $this->providerClassName);
                }
                $this->tenantDataSourceProvider = $this->appCtx->beanByClass($this->providerClassName);
            }
            $config = $this->tenantDataSourceProvider->getTenantDataSourceConfig($tenantId);
            $this->dataSources[$tenantId] = new PdoDataSource($config);
        }
        return $this->dataSources[$tenantId];
    }
}
