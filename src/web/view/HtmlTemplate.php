<?php

declare(strict_types=1);

namespace dev\winterframework\web\view;

class HtmlTemplate {

    public function __construct(
        protected string $headerFile,
        protected string $footerFile,
        protected string $contentFile,
        protected array $models = []
    ) {
    }

    public function getHeaderFile(): string {
        return $this->headerFile;
    }

    public function getFooterFile(): string {
        return $this->footerFile;
    }

    public function getContentFile(): string {
        return $this->contentFile;
    }

    public function getModels(): array {
        return $this->models;
    }
}
