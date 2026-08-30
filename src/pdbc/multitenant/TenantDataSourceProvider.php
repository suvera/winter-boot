<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\multitenant;

use dev\winterframework\pdbc\datasource\DataSourceConfig;

/**
 * Provider interface that application developers must implement
 * to supply tenant-specific DataSource configurations.
 *
 * The implementation should be registered as a #[Bean] in a #[Configuration] class.
 */
interface TenantDataSourceProvider {

    /**
     * Return the DataSourceConfig for the given tenant.
     *
     * @param string $tenantId
     * @return DataSourceConfig
     * @throws \RuntimeException if the tenant is not found
     */
    public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig;

    /**
     * Return a list of tenantIds.
     * @return array<string>
     */
    public function getAllTenantIds(): array;
}