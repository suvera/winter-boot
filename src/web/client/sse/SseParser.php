<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\sse;

use dev\winterframework\web\client\RestClientException;

/**
 * Incremental `text/event-stream` parser following the WHATWG rules: feed it
 * raw body chunks split anywhere (inside a line, between CR and LF, inside
 * a UTF-8 sequence) and it returns every event completed by a blank line.
 *
 *  - line endings: LF, CRLF or a lone CR;
 *  - `event:`, `data:` (several lines are joined with "\n"), `id:`
 *    (persists across events), `retry:` (digits only);
 *  - a single space after the colon is stripped; a line without a colon is a
 *    field with an empty value; lines starting with ':' are comments;
 *  - a leading UTF-8 BOM is skipped; unknown fields are ignored.
 *
 * A pending line plus event data is capped (default 4 MiB) so a stream that
 * never sends a newline cannot grow memory without bound.
 */
final class SseParser {
    public const DEFAULT_MAX_PENDING_BYTES = 4 * 1024 * 1024;

    private string $buffer = '';
    private bool $started = false;
    private bool $skipLeadingLf = false;
    private string $eventType = '';
    /** @var string[] */
    private array $dataLines = [];
    private int $dataBytes = 0;
    private ?string $lastEventId = null;
    private ?int $retry = null;

    public function __construct(private int $maxPendingBytes = self::DEFAULT_MAX_PENDING_BYTES) {
    }

    /**
     * @return SseEvent[] events completed by this chunk, in order
     * @throws RestClientException when the pending line/event exceeds the cap
     */
    public function feed(string $chunk): array {
        if (!$this->started) {
            $this->buffer .= $chunk;
            if (strlen($this->buffer) < 3 && str_starts_with("\xEF\xBB\xBF", $this->buffer)) {
                return [];
            }
            $this->started = true;
            if (str_starts_with($this->buffer, "\xEF\xBB\xBF")) {
                $this->buffer = substr($this->buffer, 3);
            }
        } else {
            $this->buffer .= $chunk;
        }

        if ($this->skipLeadingLf && $this->buffer !== '') {
            if ($this->buffer[0] === "\n") {
                $this->buffer = substr($this->buffer, 1);
            }
            $this->skipLeadingLf = false;
        }

        $events = [];
        $offset = 0;
        $len = strlen($this->buffer);
        while ($offset < $len) {
            $cr = strpos($this->buffer, "\r", $offset);
            $lf = strpos($this->buffer, "\n", $offset);
            if ($cr === false && $lf === false) {
                break;
            }
            if ($lf === false || ($cr !== false && $cr < $lf)) {
                $line = substr($this->buffer, $offset, $cr - $offset);
                if ($cr + 1 < $len) {
                    $offset = $this->buffer[$cr + 1] === "\n" ? $cr + 2 : $cr + 1;
                } else {
                    // CR at the end of the chunk: an LF may start the next one.
                    $offset = $cr + 1;
                    $this->skipLeadingLf = true;
                }
            } else {
                $line = substr($this->buffer, $offset, $lf - $offset);
                $offset = $lf + 1;
            }
            $event = $this->processLine($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        $this->buffer = (string)substr($this->buffer, $offset);
        if (strlen($this->buffer) + $this->dataBytes > $this->maxPendingBytes) {
            throw new RestClientException('SSE event exceeds ' . $this->maxPendingBytes . ' bytes');
        }
        return $events;
    }

    /**
     * End of stream. Per the spec an event without its closing blank line is
     * dropped; vendors that omit it can opt in with $dispatchIncomplete.
     *
     * @return SseEvent[]
     */
    public function finish(bool $dispatchIncomplete = false): array {
        $events = [];
        if ($dispatchIncomplete) {
            if ($this->buffer !== '') {
                $this->processLine($this->buffer);
            }
            $event = $this->processLine('');
            if ($event !== null) {
                $events[] = $event;
            }
        }
        $this->buffer = '';
        $this->resetEvent();
        return $events;
    }

    public function getLastEventId(): ?string {
        return $this->lastEventId;
    }

    private function processLine(string $line): ?SseEvent {
        if ($line === '') {
            return $this->dispatch();
        }
        if ($line[0] === ':') {
            return null;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);
            if ($value !== '' && $value[0] === ' ') {
                $value = substr($value, 1);
            }
        }
        switch ($field) {
            case 'event':
                $this->eventType = $value;
                break;
            case 'data':
                $this->dataLines[] = $value;
                $this->dataBytes += strlen($value) + 1;
                break;
            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->lastEventId = $value;
                }
                break;
            case 'retry':
                if ($value !== '' && ctype_digit($value)) {
                    $this->retry = (int)$value;
                }
                break;
            default:
                break;
        }
        return null;
    }

    private function dispatch(): ?SseEvent {
        if ($this->dataLines === []) {
            // No data: nothing to dispatch (the spec resets the type too).
            $this->resetEvent();
            return null;
        }
        $event = new SseEvent(
            $this->eventType !== '' ? $this->eventType : 'message',
            implode("\n", $this->dataLines),
            $this->lastEventId,
            $this->retry
        );
        $this->resetEvent();
        return $event;
    }

    private function resetEvent(): void {
        $this->eventType = '';
        $this->dataLines = [];
        $this->dataBytes = 0;
        $this->retry = null;
    }
}
