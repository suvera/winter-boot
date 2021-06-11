<?php
declare(strict_types=1);

namespace dev\winterframework\util\hash;

use lastguest\Murmur;

class MurmurHash3Provider implements HashProvider {

    public function __construct(private int $seed = 0) {
    }

    public function getHashInt(mixed $value): int {
        return Murmur::hash3_int($value, $this->seed);
    }

    public function getHash(mixed $value): string {
        return Murmur::hash3($value, $this->seed);
    }

}