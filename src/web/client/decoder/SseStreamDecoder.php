<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\decoder;

use dev\winterframework\web\client\StreamDecoder;
use dev\winterframework\web\sse\SseEvent;
use dev\winterframework\web\sse\SseParser;

/**
 * Server-sent events: delivers {@see SseEvent}s. `data: [DONE]` ends the
 * stream (not delivered, nothing after it is).
 */
final class SseStreamDecoder extends StreamDecoder {
    private SseParser $parser;
    private bool $done = false;

    public function __construct(int $maxEventBytes = SseParser::DEFAULT_MAX_PENDING_BYTES) {
        $this->parser = new SseParser($maxEventBytes);
    }

    /** @return SseEvent[] */
    public function feed(string $bytes): iterable {
        return $this->untilDone($this->parser->feed($bytes));
    }

    /** @return SseEvent[] */
    public function finish(): iterable {
        return $this->done ? [] : $this->untilDone($this->parser->finish());
    }

    public function isDone(): bool {
        return $this->done;
    }

    /** The last `id:` seen: send it as Last-Event-ID to resume the stream. */
    public function getLastEventId(): ?string {
        return $this->parser->getLastEventId();
    }

    /**
     * @param SseEvent[] $events
     * @return SseEvent[]
     */
    private function untilDone(array $events): array {
        $out = [];
        foreach ($events as $event) {
            if ($this->done) {
                break;
            }
            if ($event->isDone()) {
                $this->done = true;
                break;
            }
            $out[] = $event;
        }
        return $out;
    }
}
