<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\migrations\SqlMigrationService;
use ReflectionClass;
use winterBootTests\Support\TestCase;

class MigrationsTableDdlTest extends TestCase {

    private static function ddl(string $driver): string {
        $service = (new ReflectionClass(SqlMigrationService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SqlMigrationService::class, 'getCreateTableSql');
        return $method->invoke($service, 'winter_migrations', $driver);
    }

    // MySQL only accepts a function as a column default when it is wrapped in
    // parentheses (8.0.13+); a bare DEFAULT USER() is a syntax error (1064).
    public function testMysqlExpressionDefaultIsParenthesised(): void {
        $sql = self::ddl('mysql');
        $this->assertTrue(str_contains($sql, 'DEFAULT (USER())'), $sql);
        $this->assertFalse((bool) preg_match('/DEFAULT\s+USER\(\)/', $sql), $sql);
    }
}
