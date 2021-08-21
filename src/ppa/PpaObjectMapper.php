<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

interface PpaObjectMapper {
    public function createObject(string $class, array $data): object;

    public function mapObject(PpaEntity $obj, array $data, Entity $entity): object;

    public function generateInsertSql(PpaEntity $obj, Entity $entity): ?SqlObject;

    public function generateUpdateSql(PpaEntity $obj, Entity $entity): ?SqlObject;

    public function generateDeleteSql(PpaEntity $obj, Entity $entity): ?SqlObject;
}