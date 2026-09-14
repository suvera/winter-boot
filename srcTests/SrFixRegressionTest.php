<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\exception\SqlMigrationException;
use dev\winterframework\io\kv\KvClient;
use dev\winterframework\io\kv\KvConfig;
use dev\winterframework\io\kv\KvException;
use dev\winterframework\io\queue\QueueClient;
use dev\winterframework\io\queue\QueueConfig;
use dev\winterframework\io\queue\QueueException;
use dev\winterframework\migrations\CliSqlFileExecutor;
use winterBootTests\Support\TestCase;

final class SrMigrationCapturingExecutor extends CliSqlFileExecutor {
    public ?string $cmd = null;
    public array $env = [];

    protected function runCommand(string $cmd, array $env): void {
        $this->cmd = $cmd;
        $this->env = $env;
    }

    public function loginScript(
        string $username,
        string $password,
        string $dbname,
        string $filePath
    ): string {
        return $this->createOciLoginScript($username, $password, $dbname, $filePath);
    }
}

final class SrFixRegressionTest extends TestCase {

    private string $sqlFile = '';

    private function makeSqlFile(): string {
        $this->sqlFile = sys_get_temp_dir() . '/sr-' . uniqid() . '.sql';
        file_put_contents($this->sqlFile, 'SELECT 1;');
        return $this->sqlFile;
    }

    private function tearDownFiles(): void {
        if ($this->sqlFile !== '' && is_file($this->sqlFile)) {
            unlink($this->sqlFile);
            $this->sqlFile = '';
        }
    }

    // SR-009: Oracle password never reaches argv; login script is cleaned up.
    public function testOciPasswordNotInArgv(): void {
        try {
            $ex = new SrMigrationCapturingExecutor();
            $ex->execute('oci:dbname=XE', 'scott', 's3cr3t!', $this->makeSqlFile());
            $this->assertTrue($ex->cmd !== null);
            $this->assertFalse(str_contains($ex->cmd, 's3cr3t!'));
            $this->assertTrue(str_contains($ex->cmd, 'sqlplus'));
            if (preg_match("/@'([^']+)'/", $ex->cmd, $m) === 1) {
                $this->assertFalse(is_file($m[1]));
            }
        } finally {
            $this->tearDownFiles();
        }
    }

    // SR-009: login script is 0600; quoting only when special chars need it.
    public function testOciLoginScriptShape(): void {
        $ex = new SrMigrationCapturingExecutor();
        $script = $ex->loginScript('scott', 'plainpw123', 'XE', '/tmp/x.sql');
        try {
            $this->assertSame(0600, fileperms($script) & 0777);
            $content = file_get_contents($script);
            $this->assertTrue(str_contains($content, 'CONNECT scott/plainpw123@XE'));
            $quoted = $ex->loginScript('scott', 'p@ss word', 'XE', '/tmp/x.sql');
            try {
                $this->assertTrue(str_contains(file_get_contents($quoted), 'CONNECT scott/"p@ss word"@XE'));
            } finally {
                unlink($quoted);
            }
        } finally {
            unlink($script);
        }
    }

    // SR-009: sqlcmd takes the secret from the environment, not -P.
    public function testSqlsrvPasswordNotInArgv(): void {
        try {
            $ex = new SrMigrationCapturingExecutor();
            $ex->execute('sqlsrv:server=h;database=d', 'sa', 'p@ss', $this->makeSqlFile());
            $this->assertFalse(str_contains($ex->cmd, 'p@ss'));
            $this->assertFalse(str_contains($ex->cmd, ' -P '));
            $this->assertSame('p@ss', $ex->env['SQLCMDPASSWORD']);
        } finally {
            $this->tearDownFiles();
        }
    }

    // SR-009: mysql/pgsql keep env-based secrets out of argv.
    public function testMysqlPgsqlSecretsStayInEnv(): void {
        try {
            $ex = new SrMigrationCapturingExecutor();
            $ex->execute('mysql:host=h;dbname=d', 'root', 'pw123', $this->makeSqlFile());
            $this->assertFalse(str_contains($ex->cmd, 'pw123'));
            $this->assertSame('pw123', $ex->env['MYSQL_PWD']);

            $ex->execute('pgsql:host=h;dbname=d', 'pg', 'pw456', $this->makeSqlFile());
            $this->assertFalse(str_contains($ex->cmd, 'pw456'));
            $this->assertSame('pw456', $ex->env['PGPASSWORD']);
        } finally {
            $this->tearDownFiles();
        }
    }

    // SR-009: missing files and drivers fail before any secret handling.
    public function testMigrationInputValidation(): void {
        try {
            $ex = new SrMigrationCapturingExecutor();
            $this->assertThrows(SqlMigrationException::class, function () use ($ex) {
                $ex->execute('oci:dbname=XE', 'scott', 's3cr3t!', '/nonexistent/x.sql');
            });
            $file = $this->makeSqlFile();
            $this->assertThrows(SqlMigrationException::class, function () use ($ex, $file) {
                $ex->execute('db2:database=d', 'u', 'p', $file);
            });
        } finally {
            $this->tearDownFiles();
        }
    }

    // SR-008: client deadlines default finite and reject non-positive values.
    public function testClientTimeoutConfig(): void {
        $kv = new KvConfig('tok', 7880);
        $this->assertSame(5.0, $kv->getTimeout());
        $queue = new QueueConfig('tok', 7881);
        $this->assertSame(5.0, $queue->getTimeout());

        $this->assertThrows(KvException::class, function () {
            new KvConfig('tok', 7880, null, null, null, 0.0);
        });
        $this->assertThrows(QueueException::class, function () {
            new QueueConfig('tok', 7881, null, null, null, -2.0);
        });

        $custom = new KvConfig('tok', 7880, null, null, null, 1.5);
        $this->assertSame(1.5, $custom->getTimeout());
    }
}
