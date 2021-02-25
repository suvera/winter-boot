<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop\ex;

class AopResultOwnedException extends AopException {

    public function __construct(
        private mixed $result = null
    ) {
        parent::__construct();
    }

    public function setResult(mixed $result): void {
        $this->result = $result;
    }

    public function getResult(): mixed {
        return $this->result;
    }

}