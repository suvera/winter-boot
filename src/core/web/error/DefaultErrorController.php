<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\error;

use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use Throwable;

#[Component]
class DefaultErrorController implements ErrorController {

    #[Autowired]
    private ResponseRenderer $renderer;

    public function handleError(
        HttpRequest $request,
        ResponseEntity $response,
        HttpStatus $status,
        Throwable $t = null
    ): void {
        if ($t instanceof HttpRestException) {
            $response->withStatus($t->getStatus());
            $status = $t->getStatus();
        } else {
            $response->withStatus($status);
        }

        $response->withContentType(MediaType::APPLICATION_JSON);

        $response->setBody([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status->getValue(),
            'message' => $status->getReasonPhrase(),
            'error' => $t ? $t->getMessage() : null
        ]);

        $this->renderer->renderAndExit($response, $request);
    }
}
