<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\CallableStatement;

class PdoCallableStatement extends PdoPreparedStatement implements CallableStatement {

    public function registerOutParameter(int|string $parameter, int $sqlType, int $len = 64): void {
        $this->outParameters[$sqlType][$parameter] = $len;
    }

    public function getOutParameter(int|string $parameter): mixed {
        if (isset($this->outValues[$parameter])) {
            return $this->outValues[$parameter];
        }
        return null;
    }

    public function getOutParameters(): array {
        return $this->outValues;
    }

}