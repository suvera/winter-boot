<?php

/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace dev\winterframework\core\web\format;

use ArrayObject;
use dev\winterframework\core\System;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\io\stream\HttpOutputStream;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\http\SwooleRequest;
use dev\winterframework\web\MediaType;
use dev\winterframework\web\view\View;
use JsonSerializable;
use SplFileInfo;

class DefaultResponseRenderer extends AbstractResponseRenderer implements ResponseRenderer {
    use Wlf4p;

    public function renderAndExit(ResponseEntity $entity, HttpRequest $request): void {
        $this->render($entity, $request);

        if (!($request instanceof SwooleRequest)) {
            System::exit();
        }
    }

    public function render(ResponseEntity $entity, HttpRequest $request): void {
        $stream = $entity->getOutputStream();

        $this->checkResponseContentType($entity, $stream);

        $this->renderHeaders($entity, $stream);

        $this->renderBody($entity, $stream);

        $stream->close();
    }

    protected function checkResponseContentType(ResponseEntity $entity, HttpOutputStream $stream): void {
        if (!empty($entity->getHeaders()->getContentType())) {
            return;
        }

        $this->processResponse($entity, true, $stream);
    }

    protected function processResponse(
        ResponseEntity $entity,
        bool $checkOnly,
        HttpOutputStream $stream
    ): void {

        $body = $entity->getBody();
        $prettyPrint = JSON_PRETTY_PRINT;

        if ($body instanceof View) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_HTML);
            } else {
                $body->render($stream);
            }
        } else if (is_scalar($body)) {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_PLAIN);
            } else {
                $stream->write($body);
            }
        } else if ($body instanceof JsonSerializable) {
            $json = json_encode($body->jsonSerialize(), $prettyPrint);
            $jsonError = 'JSON Encoding error: ' . json_last_error_msg();
            if ($checkOnly) {
                $entity->withContentType(MediaType::APPLICATION_JSON);
                if ($json === false) {
                    $entity->withStatus(HttpStatus::$INTERNAL_SERVER_ERROR);
                    $entity->setBody($jsonError);
                    self::logError($jsonError);
                } else {
                    $entity->setBody($json);
                }
            } else {
                if ($json === false) {
                    $json = $jsonError;
                }
                $stream->write($json);
            }
        } else if (
            is_array($body)
            || $body instanceof ArrayObject
        ) {
            $json = json_encode($body, $prettyPrint);
            $jsonError = 'JSON Encoding error: ' . json_last_error_msg();
            if ($checkOnly) {
                $entity->withContentType(MediaType::APPLICATION_JSON);
                if ($json === false) {
                    $entity->withStatus(HttpStatus::$INTERNAL_SERVER_ERROR);
                    $entity->setBody($jsonError);
                    self::logError($jsonError);
                } else {
                    $entity->setBody($json);
                }
            } else {
                if ($json === false) {
                    $json = $jsonError;
                }
                $stream->write($json);
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
                $handle = fopen($body->getRealPath(), 'rb');
                if (false === $handle) {
                    self::logError('Could not read file ' . $body->getRealPath());
                } else {
                    while (!feof($handle)) {
                        $stream->write(fread($handle, 8192));
                    }
                    fclose($handle);
                }
            }
        } else {
            if ($checkOnly) {
                $entity->withContentType(MediaType::TEXT_PLAIN);
            } else if (isset($body)) {
                $stream->write($body);
            }
        }
    }

    protected function renderBody(ResponseEntity $entity, HttpOutputStream $stream): void {
        $this->processResponse($entity, false, $stream);
        $stream->flush();
    }
}
