<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\io\stream\SwooleOutputStream;
use Swoole\Http\Response;

class SwooleResponseEntity extends ResponseEntity {

    public function __construct(Response $response) {
        parent::__construct();
        $this->outputStream = new SwooleOutputStream($response);
    }

}