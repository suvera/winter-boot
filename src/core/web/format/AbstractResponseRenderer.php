<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\format;

use dev\winterframework\io\stream\HttpOutputStream;
use dev\winterframework\web\http\ResponseEntity;

abstract class AbstractResponseRenderer {

    protected function renderHeaders(ResponseEntity $entity, HttpOutputStream $stream): void {
        /**
         * STEP - 1 : HTTP Status
         */
        $status = $entity->getStatus();
        $stream->setStatus($status->getValue(), $status->getReasonPhrase(), 'HTTP/1.1');

        /**
         * STEP - 2 : HTTP Headers
         */
        $headers = $entity->getHeaders();
        foreach ($headers->getAll() as $name => $values) {
            foreach ($values as $value) {
                $stream->writeHeader($name, $value);
            }
        }

        /**
         * STEP - 3 : HTTP Cookies
         */
        $cookies = $entity->getCookies();
        $stream->setCookies($cookies);

    }
}