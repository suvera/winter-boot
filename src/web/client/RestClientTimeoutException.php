<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * A streaming call waited longer than the read (idle) timeout for the next
 * bytes, or longer than the connect timeout for the connection. The message
 * carries only the method and the query-stripped URL.
 */
class RestClientTimeoutException extends RestClientException {
}
