<?php
declare(strict_types=1);

namespace test\winterframework\pdbc\classess;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class TestPdbc001 {

    #[Autowired]
    private PdbcTemplate $pdbc;

    public function templateTest01(): mixed {
        return $this->pdbc->queryForScalar("select 'Hello, Suvera' as COLUMN1", []);
    }

    public function templateTest02(): mixed {
        return $this->pdbc->queryForMap("select 'Hello, Suvera' as COLUMN1", []);
    }
}