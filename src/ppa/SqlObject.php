<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVars;

class SqlObject {
    public function __construct(
        protected string $sql,
        protected BindVars $bindVars,
        protected ?OutBindVars $outBindVars = null
    ) {
    }

    public function getSql(): string {
        return $this->sql;
    }

    public function setSql(string $sql): void {
        $this->sql = $sql;
    }

    public function getBindVars(): BindVars {
        return $this->bindVars;
    }

    public function setBindVars(BindVars $bindVars): void {
        $this->bindVars = $bindVars;
    }

    public function getOutBindVars(): ?OutBindVars {
        return $this->outBindVars;
    }

    public function setOutBindVars(OutBindVars $outBindVars): void {
        $this->outBindVars = $outBindVars;
    }

}