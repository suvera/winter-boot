<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\txn\PlatformTransactionManager;

#[Configuration]
class PdoTemplateProvider {

    #[Bean]
    public function getDefaultTemplate(DataSource $dataSource): PdbcTemplate {
        return new PdoTemplate($dataSource);
    }

    #[Bean]
    public function getDefaultTransactionManager(DataSource $dataSource): PlatformTransactionManager {
        /** @var PdoDataSource $dataSource */
        return new PdoTransactionManager($dataSource);
    }
}