<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc;

interface CallableStatement extends PreparedStatement {

    public function registerOutParameter(string|int $parameter, int $sqlType, int $len = 64): void;

    public function getOutParameter(string|int $parameter): mixed;

    public function getOutParameters(): array;
}