<?php
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

class RpmDefine {

    protected string $statement;

    public function getStatement(): string {
        return $this->statement;
    }

    public function setStatement(string $statement): void {
        $this->statement = $statement;
    }

}