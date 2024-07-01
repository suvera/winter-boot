<?php

declare(strict_types=1);

namespace dev\winterframework\pdbc\datasource;

use dev\winterframework\stereotype\JsonProperty;

class DataSourceConfig {
    protected string $name;

    #[JsonProperty("isPrimary")]
    protected bool $primary = false;

    protected string $url;
    protected string $username = '';
    protected string $password = '';

    protected string $validationQuery = '';
    protected string $driverClass;

    #[JsonProperty("connection.persistent")]
    protected bool $persistent = false;

    #[JsonProperty("connection.errorMode")]
    protected string $errorMode = 'ERRMODE_EXCEPTION';

    #[JsonProperty("connection.columnsCase")]
    protected string $columnsCase = 'ERRMODE_EXCEPTION';

    #[JsonProperty("connection.timeoutSecs")]
    protected int $timeoutSecs = 30;

    #[JsonProperty("connection.autoCommit")]
    protected bool $autoCommit = true;

    #[JsonProperty("connection.rowsPrefetch")]
    protected int $rowsPrefetch = 100;

    #[JsonProperty("connection.idleTimeout")]
    protected int $idleTimeout = 600;

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function isPrimary(): bool {
        return $this->primary;
    }

    public function setPrimary(bool $primary): void {
        $this->primary = $primary;
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function setUrl(string $url): void {
        $this->url = $url;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function setUsername(string $username): void {
        $this->username = $username;
    }

    public function getPassword(): string {
        return $this->password;
    }

    public function setPassword(string $password): void {
        $this->password = $password;
    }

    public function getValidationQuery(): string {
        return $this->validationQuery;
    }

    public function setValidationQuery(string $validationQuery): void {
        $this->validationQuery = $validationQuery;
    }

    public function getDriverClass(): string {
        return $this->driverClass;
    }

    public function setDriverClass(string $driverClass): void {
        $this->driverClass = $driverClass;
    }

    public function isPersistent(): bool {
        return $this->persistent;
    }

    public function setPersistent(bool $persistent): void {
        $this->persistent = $persistent;
    }

    public function getErrorMode(): string {
        return $this->errorMode;
    }

    public function setErrorMode(string $errorMode): void {
        $this->errorMode = $errorMode;
    }

    public function getColumnsCase(): string {
        return $this->columnsCase;
    }

    public function setColumnsCase(string $columnsCase): void {
        $this->columnsCase = $columnsCase;
    }

    public function getTimeoutSecs(): int {
        return $this->timeoutSecs;
    }

    public function setTimeoutSecs(int $timeoutSecs): void {
        $this->timeoutSecs = $timeoutSecs;
    }

    public function isAutoCommit(): bool {
        return $this->autoCommit;
    }

    public function setAutoCommit(bool $autoCommit): void {
        $this->autoCommit = $autoCommit;
    }

    public function getRowsPrefetch(): int {
        return $this->rowsPrefetch;
    }

    public function setRowsPrefetch(int $rowsPrefetch): void {
        $this->rowsPrefetch = $rowsPrefetch;
    }

    public function getIdleTimeout(): int {
        return $this->idleTimeout;
    }

    public function setIdleTimeout(int $idleTimeout): void {
        $this->idleTimeout = $idleTimeout;
    }
}
