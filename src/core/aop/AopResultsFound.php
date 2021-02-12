<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use RuntimeException;

class AopResultsFound extends RuntimeException {

    public function __construct(
        private mixed $result
    ) {
        parent::__construct();
    }

    public function getResult(): mixed {
        return $this->result;
    }

}