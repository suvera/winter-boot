<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use Throwable;

class AopExecutionContext {
    const QUEUED = 1;
    const BEGIN_DONE = 2;
    const BEGIN_FAILED = 3;
    const FAILED = 4;
    const COMMIT_DONE = 5;
    const COMMIT_FAILED = 6;

    const EXECUTION_OK = 60;
    const EXECUTION_STOP = 61;
    const EXECUTION_SKIP_ME = 62;
    const EXECUTION_SKIP_OTHERS = 63;

    private array $skippedAspects = [];
    private array $variables = [];
    private mixed $result = null;
    private int $currentStatus = self::QUEUED;
    private int $executionStatus = self::EXECUTION_OK;
    private ?Throwable $exception = null;

    public function __construct(
        private object $object,
        private array $arguments
    ) {
    }

    public function getObject(): object {
        return $this->object;
    }

    public function getArguments(): array {
        return $this->arguments;
    }

    public function getSkippedAspects(): array {
        return $this->skippedAspects;
    }

    public function setSkippedAspects(array $skippedAspects): void {
        $this->skippedAspects = $skippedAspects;
    }

    public function addSkippedAspect(int $aspectIndex): void {
        $this->skippedAspects[$aspectIndex] = $aspectIndex;
    }

    public function isSkippedAspect(int $aspectIndex): bool {
        return isset($this->skippedAspects[$aspectIndex]);
    }

    public function setVariable(string|int $name, mixed $value): void {
        $this->variables[$name] = $value;
    }

    public function getVariable(string|int $name): mixed {
        return isset($this->variables[$name]) ? $this->variables[$name] : null;
    }

    public function hasVariable(string|int $name): bool {
        return array_key_exists($name, $this->variables);
    }

    public function getCurrentStatus(): int {
        return $this->currentStatus;
    }

    public function setCurrentStatus(int $currentStatus): void {
        $this->currentStatus = $currentStatus;
    }

    public function getVariables(): array {
        return $this->variables;
    }

    public function setVariables(array $variables): void {
        $this->variables = $variables;
    }

    public function setBeginFailed(): void {
        $this->currentStatus = self::BEGIN_FAILED;
    }

    public function isBeginFailed(): bool {
        return $this->currentStatus === self::BEGIN_FAILED;
    }

    public function setBeginDone(): void {
        $this->currentStatus = self::BEGIN_DONE;
    }

    public function isBeginDone(): bool {
        return $this->currentStatus === self::BEGIN_DONE;
    }

    public function setFailed(): void {
        $this->currentStatus = self::FAILED;
    }

    public function isFailed(): bool {
        return $this->currentStatus === self::FAILED;
    }

    public function isAnyFailed(): bool {
        return $this->isBeginFailed() || $this->isFailed() || $this->isCommitFailed();
    }

    public function setCommitFailed(): void {
        $this->currentStatus = self::COMMIT_FAILED;
    }

    public function isCommitFailed(): bool {
        return $this->currentStatus === self::COMMIT_FAILED;
    }

    public function setSuccess(): void {
        $this->currentStatus = self::COMMIT_DONE;
    }

    public function isSuccess(): bool {
        return $this->currentStatus === self::COMMIT_DONE;
    }

    public function getResult(): mixed {
        return $this->result;
    }

    public function setResult(mixed $result): void {
        $this->result = $result;
    }

    public function getException(): ?Throwable {
        return $this->exception;
    }

    public function setException(Throwable $exception): void {
        $this->exception = $exception;
    }

    public function getExecutionStatus(): int {
        return $this->executionStatus;
    }

    public function setExecutionStatus(int $executionStatus): void {
        $this->executionStatus = $executionStatus;
    }

    public function stopExecution(mixed $result): void {
        $this->result = $result;
        $this->executionStatus = self::EXECUTION_STOP;
    }

    public function skipExecutionMe(): void {
        $this->executionStatus = self::EXECUTION_SKIP_ME;
    }

    public function skipExecutionOthers(): void {
        $this->executionStatus = self::EXECUTION_SKIP_OTHERS;
    }

    public function setExecutionOk(): void {
        $this->executionStatus = self::EXECUTION_OK;
    }

    public function isExecutionOk(): bool {
        return $this->executionStatus === self::EXECUTION_OK;
    }

    public function isStopExecution(): bool {
        return $this->executionStatus === self::EXECUTION_STOP;
    }

    public function isSkipExecutionMe(): bool {
        return $this->executionStatus === self::EXECUTION_SKIP_ME;
    }

    public function isSkipExecutionOthers(): bool {
        return $this->executionStatus === self::EXECUTION_SKIP_OTHERS;
    }

}