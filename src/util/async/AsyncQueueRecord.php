<?php
declare(strict_types=1);

namespace dev\winterframework\util\async;

class AsyncQueueRecord {

    private int $id = 0;
    private int $timestamp;
    private string $className;
    private string $methodName;
    private string $arguments;
    private int $workerId;

    public function getId(): int {
        return $this->id;
    }

    public function setId(int $id): void {
        $this->id = $id;
    }

    public function getTimestamp(): int {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): void {
        $this->timestamp = $timestamp;
    }

    public function getClassName(): string {
        return $this->className;
    }

    public function setClassName(string $className): void {
        $this->className = $className;
    }

    public function getMethodName(): string {
        return $this->methodName;
    }

    public function setMethodName(string $methodName): void {
        $this->methodName = $methodName;
    }

    public function getArguments(): string {
        return $this->arguments;
    }

    public function setArguments(string $arguments): void {
        $this->arguments = $arguments;
    }

    public function getWorkerId(): int {
        return $this->workerId;
    }

    public function setWorkerId(int $workerId): void {
        $this->workerId = $workerId;
    }

    public function toArray(): array {
        return [
            'className' => $this->className,
            'methodName' => $this->methodName,
            'timestamp' => $this->timestamp,
            'arguments' => $this->arguments,
            'workerId' => $this->workerId,
            'id' => $this->id,
        ];
    }

    public static function fromArray(int $id, array $data): self {
        $self = new self();
        $self->className = $data['className'];
        $self->methodName = $data['methodName'];
        $self->timestamp = $data['timestamp'];
        $self->arguments = $data['arguments'];
        $self->workerId = $data['workerId'];

        if (isset($data['id'])) {
            $self->id = $data['id'];
        } else if ($id != 0) {
            $self->id = $id;
        }

        return $self;
    }
}