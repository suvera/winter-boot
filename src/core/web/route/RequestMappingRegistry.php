<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\route;

use dev\winterframework\core\web\MatchedRequestMapping;
use dev\winterframework\stereotype\web\RequestMapping;

interface RequestMappingRegistry {

    public function put(RequestMapping $mapping);

    public function find(string $path, string $method): ?MatchedRequestMapping;

    public function delete(string $path): void;

    public function getAll(): array;
}