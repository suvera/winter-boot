<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\format;

use ArrayObject;
use dev\winterframework\core\System;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use dev\winterframework\web\view\View;
use JsonSerializable;
use SplFileInfo;

class DefaultResponseRenderer extends AbstractResponseRenderer implements ResponseRenderer {

    public function renderAndExit(ResponseEntity $entity): void {
        $this->render($entity);
        System::exit();
    }

    public function render(ResponseEntity $entity): void {
        $this->checkResponseContentType($entity);

        $this->renderHeaders($entity);

        $this->renderBody($entity);
    }

    private function checkResponseContentType(ResponseEntity $entity): void {
        if (!empty($entity->getHeaders()->getContentType())) {
            return;
        }

        $this->processResponse($entity, true);
    }

    private function processResponse(
        ResponseEntity $entity,
        bool $checkOnly = false
    ): void {

        $body = $entity->getBody();
        $prettyPrint = JSON_PRETTY_PRINT;

        if ($body instanceof View) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_HTML);
            } else {
                $body->render();
            }
        } else if (is_scalar($body)) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_PLAIN);
            } else {
                echo $body;
            }
        } else if ($body instanceof JsonSerializable) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::APPLICATION_JSON);
            } else {
                echo json_encode($body->jsonSerialize(), $prettyPrint);
            }
        } else if (is_array($body)
            || $body instanceof ArrayObject
        ) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::APPLICATION_JSON);
            } else {
                echo json_encode($body, $prettyPrint);
            }
        } else if ($body instanceof SplFileInfo) {
            if ($checkOnly) {
                $entity->withContentLength($body->getSize());
                $entity->withContentType(mime_content_type($body->getRealPath()));
                $entity->getHeaders()->setIfNot(
                    'Content-Disposition',
                    'attachment; filename=' . $body->getFileName()
                );
            } else {
                readfile($body->getRealPath());
            }
        } else {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_PLAIN);
            } else {
                echo $body;
            }
        }
    }

    private function renderBody(ResponseEntity $entity): void {
        $this->processResponse($entity, false);
        flush();
    }

}