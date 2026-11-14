<?php

declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\web\MediaType;

/**
 * A response whose body is written in chunks as it is produced, returned
 * from a controller method:
 *
 * ```php
 * #[GetMapping(path: '/report')]
 * public function report(): StreamingResponseEntity {
 *     return StreamingResponseEntity::of(function (ResponseWriter $out) {
 *         foreach ($this->rows() as $row) {
 *             if (!$out->write(json_encode($row) . "\n")) {
 *                 return; // client disconnected: stop working
 *             }
 *         }
 *     }, 'application/x-ndjson');
 * }
 * ```
 *
 * The producer runs after the controller method returned (method-level
 * advice such as #[Transactional] has already finished), with the request
 * still bound. Status and headers go out with the first chunk, using HTTP
 * chunked transfer and no compression. If the producer throws before
 * writing anything the request fails like any other (error controller);
 * after the first chunk the error is logged and the response ends.
 * Interceptors' postHandle() and afterCompletion() run once, after the
 * stream ended: headers they set then are not sent.
 */
class StreamingResponseEntity extends ResponseEntity {

    /**
     * @param callable(ResponseWriter): void $producer
     */
    public static function of(callable $producer, string $contentType = MediaType::APPLICATION_OCTET_STREAM): self {
        $entity = new self();
        $entity->withStatus(HttpStatus::$OK);
        $entity->withContentType($contentType);
        $entity->getHeaders()->set('Cache-Control', 'no-cache');
        $entity->getHeaders()->set('X-Accel-Buffering', 'no');
        $entity->setBody(new StreamingBody($producer));
        return $entity;
    }

    /**
     * Server-sent events (text/event-stream). The headers are sent right
     * away (with an initial comment), so the client sees the stream open
     * before the first event. $heartbeatSeconds > 0 starts
     * {@see SseEmitter::heartbeat()}; it is stopped when the producer returns.
     *
     * @param callable(SseEmitter): void $producer
     */
    public static function sse(callable $producer, float $heartbeatSeconds = 0): self {
        $entity = self::of(static function (ResponseWriter $writer) use ($producer, $heartbeatSeconds): void {
            $emitter = new SseEmitter($writer);
            try {
                if (!$emitter->comment('stream open')) {
                    return;
                }
                if ($heartbeatSeconds > 0) {
                    $emitter->heartbeat($heartbeatSeconds);
                }
                $producer($emitter);
            } finally {
                $emitter->stopHeartbeat();
            }
        }, MediaType::TEXT_EVENT_STREAM);
        return $entity;
    }
}
