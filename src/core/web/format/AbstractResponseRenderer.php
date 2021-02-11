<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\format;

use dev\winterframework\web\http\ResponseEntity;

abstract class AbstractResponseRenderer {

    protected function renderHeaders(ResponseEntity $entity): void {
        /**
         * STEP - 1 : HTTP Status
         */
        $status = $entity->getStatus();
        //http_response_code($status->getValue());
        header("HTTP/1.1 " . $status->getValue()
            . ' '
            . $status->getReasonPhrase()
        );

        /**
         * STEP - 2 : HTTP Headers
         */
        $headers = $entity->getHeaders();
        foreach ($headers->getAll() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value");
            }
        }

        /**
         * STEP - 3 : HTTP Cookies
         */
        $cookies = $entity->getCookies();
        foreach ($cookies as $cookie) {
            setcookie(
                $cookie->name,
                $cookie->value,
                $cookie->expires,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httponly
            );
        }

    }
}