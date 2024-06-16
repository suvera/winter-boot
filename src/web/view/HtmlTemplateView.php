<?php

declare(strict_types=1);

namespace dev\winterframework\web\view;

use dev\winterframework\io\stream\HttpOutputStream;

class HtmlTemplateView implements View {

    public function __construct(protected HtmlTemplate $tpl) {
    }

    public function render(HttpOutputStream $outputStream): void {
        extract($this->tpl->getModels());
        if (!empty($this->tpl->getHeaderFile())) {
            include $this->tpl->getHeaderFile();
        }

        if (!empty($this->tpl->getContentFile())) {
            include $this->tpl->getContentFile();
        }

        if (!empty($this->tpl->getFooterFile())) {
            include $this->tpl->getFooterFile();
        }
    }
}
