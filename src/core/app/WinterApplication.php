<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

interface WinterApplication {

    public function run(string $appClass): void;

    public function getBootVersion(): string;

}