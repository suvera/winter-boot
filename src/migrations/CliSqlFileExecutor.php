<?php

declare(strict_types=1);

namespace dev\winterframework\migrations;

use dev\winterframework\exception\SqlMigrationException;
use dev\winterframework\util\log\Wlf4p;

class CliSqlFileExecutor {
    use Wlf4p;

    /**
     * Executes the SQL file via native database CLI shell commands.
     */
    public function execute(string $url, string $username, string $password, string $filePath): void {
        if (!file_exists($filePath)) {
            throw new SqlMigrationException("SQL file not found at: {$filePath}");
        }

        $parsed = $this->parseDsn($url);
        $driver = $parsed['driver'];
        $params = $parsed['params'];

        $cmd = '';
        $env = [];

        switch ($driver) {
            case 'pgsql':
                $host = $params['host'] ?? '127.0.0.1';
                $port = $params['port'] ?? '5432';
                $dbname = $params['dbname'] ?? '';
                
                $env = ['PGPASSWORD' => $password];
                $cmd = sprintf(
                    'psql -h %s -p %s -U %s -d %s -f %s',
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($dbname),
                    escapeshellarg($filePath)
                );
                break;

            case 'mysql':
                $host = $params['host'] ?? '127.0.0.1';
                $port = $params['port'] ?? '3306';
                $dbname = $params['dbname'] ?? '';
                
                $env = ['MYSQL_PWD' => $password];
                $cmd = sprintf(
                    'mysql -h %s -P %s -u %s %s < %s',
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($dbname),
                    escapeshellarg($filePath)
                );
                break;

            case 'sqlite':
                $dbPath = $params['path'] ?? ':memory:';
                $cmd = sprintf(
                    'sqlite3 %s < %s',
                    escapeshellarg($dbPath),
                    escapeshellarg($filePath)
                );
                break;

            case 'oci':
                $dbname = $params['dbname'] ?? '';
                $cmd = sprintf(
                    'sqlplus -S %s/%s@%s @%s',
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($dbname),
                    escapeshellarg($filePath)
                );
                break;

            case 'sqlsrv':
            case 'dblib':
                $server = $params['server'] ?? $params['host'] ?? '127.0.0.1';
                $dbname = $params['database'] ?? $params['dbname'] ?? '';
                $cmd = sprintf(
                    'sqlcmd -S %s -U %s -P %s -d %s -i %s',
                    escapeshellarg($server),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($dbname),
                    escapeshellarg($filePath)
                );
                break;

            default:
                throw new SqlMigrationException("CLI execution not supported for database driver: '{$driver}'");
        }

        $this->runCommand($cmd, $env);
    }

    /**
     * Parses a PDO-style DSN string into key-value components.
     */
    private function parseDsn(string $dsn): array {
        $parts = explode(':', $dsn, 2);
        if (count($parts) < 2) {
            return ['driver' => strtolower($parts[0] ?? ''), 'params' => []];
        }

        $driver = strtolower($parts[0]);
        $paramString = $parts[1];
        $params = [];

        foreach (explode(';', $paramString) as $pair) {
            if (strpos($pair, '=') !== false) {
                list($key, $value) = explode('=', $pair, 2);
                $params[trim(strtolower($key))] = trim($value);
            } else {
                $params['path'] = trim($pair);
            }
        }

        return [
            'driver' => $driver,
            'params' => $params
        ];
    }

    /**
     * Executes the built command securely with proc_open.
     */
    private function runCommand(string $cmd, array $env): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $processEnv = array_merge(getenv(), $env);

        $process = proc_open($cmd, $descriptors, $pipes, null, $processEnv);

        if (is_resource($process)) {
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            if ($stdout !== '') {
                $this->logInfo("SQL execution output:\n" . $stdout);
            }

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            if ($stderr !== '') {
                $this->logWarning("SQL execution warnings:\n" . $stderr);
            }

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new SqlMigrationException(
                    "SQL execution failed with exit code {$exitCode}.\nCommand: {$cmd}"
                );
            }
        } else {
            throw new SqlMigrationException("Failed to spawn process for command: {$cmd}");
        }
    }
}
