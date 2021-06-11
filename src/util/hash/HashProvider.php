<?php
declare(strict_types=1);

namespace dev\winterframework\util\hash;

interface HashProvider {

    public function getHashInt(mixed $value): int;

    public function getHash(mixed $value): string;
}