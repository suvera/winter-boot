<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\type\Queue;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class SharedQueue implements Queue {
    use Wlf4p;

    protected ?QueueClient $client = null;

    public function __construct(
        protected QueueConfig $config,
        protected string $queueName,
        protected int $capacity = 0,
        protected int $retries = 5
    ) {
    }

    protected function getClient(): void {
        if (!isset($this->client)) {
            $this->client = new QueueClient($this->config);
        }
    }

    public function add(mixed $item, int $timeoutMs = 0): bool {
        $value = serialize($item);
        $i = $this->retries;
        while ($i > 0) {
            try {
                $this->getClient();

                return $this->client->enqueue($this->queueName, $value);
            } catch (Throwable $e) {
                self::logException($e);
                usleep(200000);
            }
            $i--;
        }

        return false;
    }

    public function poll(int $timeoutMs = 0): mixed {
        $i = $this->retries;
        while ($i > 0) {
            try {
                $this->getClient();
                $data = $this->client->dequeue($this->queueName);
                if (is_null($data)) {
                    return null;
                }
                return unserialize($data);
            } catch (Throwable $e) {
                self::logException($e);
                usleep(200000);
            }
            $i--;
        }
        return null;
    }

    public function isUnbounded(): bool {
        return true;
    }

    public function isCountable(): bool {
        return true;
    }

    public function size(): int {
        $i = $this->retries;
        while ($i > 0) {
            try {
                $this->getClient();
                return $this->client->size($this->queueName);
            } catch (Throwable $e) {
                self::logException($e);
            }
            $i--;
        }

        return 0;
    }
}