<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

use Closure;
use dev\winterframework\io\stream\SwooleOutputStream;
use Swoole\Http\Response;

class SwooleResponseEntity extends ResponseEntity {

    /**
     * @param Closure(): bool|null $connectionProbe see {@see SwooleOutputStream}
     */
    public function __construct(Response $response, ?Closure $connectionProbe = null) {
        parent::__construct();
        $this->outputStream = new SwooleOutputStream($response, $connectionProbe);
    }

}