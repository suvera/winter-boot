<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;

#[Configuration]
class PdoTemplateProvider {

    #[Bean]
    public function getDefaultTemplate(DataSource $dataSource): PdbcTemplate {
        return new PdoTemplate($dataSource);
    }
}