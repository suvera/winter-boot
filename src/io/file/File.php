<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

interface File {

    public function canRead(): bool;

    public function canWrite(): bool;

    public function delete(): bool;

    public function exists(): bool;

    public function isDirectory(): bool;

    public function isFile(): bool;

    public function getRealPath(): string;

    public function getName(): string;

    public function lastModified(): int;

    public function setPermissions(int $perms): bool;

    public function openStream(FileMode $mode): FileStream;

}