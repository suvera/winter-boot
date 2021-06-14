<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc;

interface DataSource {

    public function getConnection(): Connection;

    public function getLoginTimeout(): int;

    public function setLoginTimeout(int $timeoutSecs): void;

    public function checkIdleConnection(): void;
}