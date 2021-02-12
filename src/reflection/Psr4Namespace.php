<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\bombok\Data;

/**
 * @method getNamespacePrefix(): string
 * @method getBaseDirectory(): string
 */
class Psr4Namespace {
    use Data;

    private string $namespacePrefix;
    private string $baseDirectory;

    /**
     * Psr4Record constructor.
     * @param string $namespacePrefix
     * @param string $baseDirectory
     */
    public function __construct(string $namespacePrefix, string $baseDirectory) {
        $this->namespacePrefix = rtrim($namespacePrefix, '/');
        $this->baseDirectory = rtrim($baseDirectory, '\\');
    }


}