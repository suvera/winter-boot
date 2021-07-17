<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

class PidInfo {

    protected array $data = [];
    protected string $name = '';

    public function __construct(int $pid = 0) {
        $this->data['pid'] = $pid;
    }

    public function getArray(): array {
        return $this->data;
    }

    public function getParentPid(): int {
        return $this->data['parentPid'];
    }

    public function setParentPid(int $parentPid): void {
        $this->data['parentPid'] = $parentPid;
    }

    public function getThreads(): int {
        return $this->data['threads'];
    }

    public function setThreads(int $threads): void {
        $this->data['threads'] = $threads;
    }

    public function getState(): string {
        return $this->data['state'];
    }

    public function setState(string $state): void {
        $this->data['state'] = $state;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getFdSize(): int {
        return $this->data['fdSize'];
    }

    public function setFdSize(int $fdSize): void {
        $this->data['fdSize'] = $fdSize;
    }

    public function getVirtualMemorySize(): int {
        return $this->data['virtualMemorySize'];
    }

    public function setVirtualMemorySize(int $virtualMemorySize): void {
        $this->data['virtualMemorySize'] = $virtualMemorySize;
    }

    public function getVirtualMemoryPeak(): int {
        return $this->data['virtualMemoryPeak'];
    }

    public function setVirtualMemoryPeak(int $virtualMemoryPeak): void {
        $this->data['virtualMemoryPeak'] = $virtualMemoryPeak;
    }

    public function getVirtualMemoryRss(): int {
        return $this->data['virtualMemoryRss'];
    }

    public function setVirtualMemoryRss(int $virtualMemoryRss): void {
        $this->data['virtualMemoryRss'] = $virtualMemoryRss;
    }

    public function getRunningSince(): int {
        return $this->data['runningSince'];
    }

    public function setRunningSince(int $runningSince): void {
        $this->data['runningSince'] = $runningSince;
    }

}