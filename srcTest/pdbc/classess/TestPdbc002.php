<?php
declare(strict_types=1);

namespace test\winterframework\pdbc\classess;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

#[Service]
class TestPdbc002 {

    #[Autowired]
    private PdbcTemplate $pdbc;

    private int $id = 1;

    #[Transactional]
    public function transactionTest01(): mixed {
        echo get_class($this) . "\n";

        $this->pdbc->execute("create table IF NOT EXISTS contacts (id INTEGER PRIMARY KEY, 
                name TEXT NOT NULL,	email TEXT) ");

        $this->id++;
        $this->pdbc->execute(
            "delete from contacts where id = ?",
            [1 => $this->id]
        );

        return $this->pdbc->execute(
            "insert into contacts(id, name) values ( :id, :name)",
            ['id' => $this->id, 'name' => 'Suvera']
        );
    }

    public function transactionTest02(): array {
        return $this->pdbc->queryForList("select * from contacts");
    }
}