<?php
declare(strict_types=1);

namespace dev\winterframework\web\view;

use dev\winterframework\io\stream\HttpOutputStream;

interface View {

    public function render(HttpOutputStream $outputStream): void;

}