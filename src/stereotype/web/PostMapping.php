<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD)]
class PostMapping extends RequestMapping {
    public function __construct(
        string|array $path = '',
        ?string $name = null,
        ?array $consumes = null,
        ?array $produces = null
    ) {
        parent::__construct($path, [RequestMethod::POST], $name, $consumes, $produces);
    }
}