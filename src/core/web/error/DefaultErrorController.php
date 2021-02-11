<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\error;

use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use Throwable;

#[Component]
class DefaultErrorController implements ErrorController {

    #[Autowired]
    private ResponseRenderer $renderer;

    public function handleError(HttpStatus $status, Throwable $t = null): void {

        $e = ResponseEntity::status($status)->withContentType(MediaType::APPLICATION_JSON);
        
        $e->setBody([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status->getValue(),
            'message' => $status->getReasonPhrase(),
            'error' => $t ? $t->getMessage() : null
        ]);

        $this->renderer->renderAndExit($e);
    }

}