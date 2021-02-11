<?php
declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\stereotype\web\RequestMapping;

class MatchedRequestMapping {

    public function __construct(
        private RequestMapping $mapping,
        private array $matching
    ) {
    }

    /**
     * @return RequestMapping
     */
    public function getMapping(): RequestMapping {
        return $this->mapping;
    }

    /**
     * @return array
     */
    public function getMatching(): array {
        return $this->matching;
    }

}