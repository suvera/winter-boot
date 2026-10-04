<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\exception\WinterException;

/**
 * Base failure for every RestTemplate call, mirroring Spring's
 * RestClientException. Transport errors, bad URLs, unmappable bodies and
 * HTTP error statuses (via subclasses) all surface as this type, so callers
 * only ever catch one hierarchy. It extends WinterException (a RuntimeException),
 * so no checked-exception plumbing is needed.
 */
class RestClientException extends WinterException {
}
