# SQL Migrations

This feature provides SQL migration capabilities for Winter Boot Framework, allowing you to execute SQL files against configured datasources with automatic tracking and version management.

## Quick Start

### 1. Enable Migrations in application.yaml

```yaml
datasource:
    -   name: defaultdb
        isPrimary: true
        url: "sqlite::memory:"
        migrations:
            enabled: true
```

### 2. Create SQL Directory Structure

```bash
mkdir -p /migrations/defaultdb
```

### 3. Add SQL Files

Create `/migrations/defaultdb/001-init-schema.sql`:

```sql
CREATE TABLE users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 4. Run Migrations

```bash
# Using PHAR (migrate stub is built into your app PHAR)
php your-app.phar migrate --sqlPath /migrations

# Using CLI script directly
php bin/sql-migrate.php --sqlPath /migrations
```

### 5. Verify

Check that `winter_migrations` table was created and your migration is recorded.

## Configuration

### Enable Migrations for Standalone Datasources

Add `migrations.enabled: true` to your datasource configuration:

```yaml
datasource:
    -   name: defaultdb
        url: "mysql:host=localhost;dbname=myapp"
        migrations:
            enabled: true
```

## SQL Directory Structure

```
/migrations/
├── defaultdb/              # Standalone datasource
│   ├── 001-init-schema.sql
│   ├── 002-add-users.sql
│   └── release-1.0/        # Release sub-folders (optional)
│       └── 003-update.sql
├── admindb/                # Another standalone datasource
│   └── 001-init-admin.sql
└── tenantdb/               # Multi-tenant datasource
    └── 001-tenant-schema.sql
```

**Requirements:**
- SQL files must be in `{datasource-name}/` sub-folders
- Support release-based organization with sub-folders
- Files executed in alphabetical order

## Database Table

The framework automatically creates `winter_migrations` table:

```sql
CREATE TABLE winter_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration_path VARCHAR(512) NOT NULL UNIQUE,
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_by VARCHAR(100)
);
```

> **Note:** The table is automatically created for each database type with appropriate syntax.

## Usage

### PHAR Package

Build PHAR using `WinterPharTask` (the `migrate` stub is automatically included in your app PHAR):

```bash
# Execute migrations
php your-app.phar migrate --sqlPath /migrations

# With config directory
php your-app.phar migrate --sqlPath /migrations --configDir /app/config
```

### Init Container (Kubernetes)

```yaml
initContainers:
  - name: sql-migrations
    image: your-app:latest
    command: ["/bin/bash", "/app/bin/sql-migrate-init-container.sh"]
    env:
      - name: SQL_MIGRATION_PATH
        value: /migrations
      - name: CONFIG_DIR
        value: /app/config
    volumeMounts:
      - name: migrations
        mountPath: /migrations
      - name: config
        mountPath: /app/config
```

### Direct Command

```shell
php bin/sql-migrate.php migrate --sqlPath /path/to/migrations --configDir /path/to/config
```

## Usage

### Using the PHAR Package

#### Build PHAR

The PHAR can be built using the `WinterPharTask` in your build system. The `migrate` stub is automatically included.

#### Execute Migrations

```bash
# Using PHAR (migrate stub is built into your app PHAR)
php your-app.phar migrate --sqlPath /path/to/migrations

# With config directory
php your-app.phar migrate --sqlPath /path/to/migrations --configDir /path/to/config
```

### Using Init Container (Kubernetes)

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: my-app
spec:
  initContainers:
    - name: sql-migrations
      image: my-app:latest
      command: ["/bin/bash", "/app/bin/sql-migrate-init-container.sh"]
      env:
        - name: SQL_MIGRATION_PATH
          value: /migrations
        - name: CONFIG_DIR
          value: /app/config
      volumeMounts:
        - name: migrations
          mountPath: /migrations
        - name: config
          mountPath: /app/config
        - name: app
          mountPath: /app
  containers:
    - name: app
      image: my-app:latest
      volumeMounts:
        - name: migrations
          mountPath: /migrations
        - name: config
          mountPath: /app/config
  volumes:
    - name: migrations
      persistentVolumeClaim:
        claimName: migrations-pvc
    - name: config
      configMap:
        name: app-config
    - name: app
      emptyDir: {}
```

### Using the Command Directly

```php
<?php
declare(strict_types=1);

use dev\winterframework\pdbc\migration\SqlMigrationCommand;

require_once(__DIR__ . '/vendor/autoload.php');

$command = new SqlMigrationCommand();
$command->sqlPath = '/path/to/migrations';
$command->configDir = '/path/to/config';

$exitCode = $command->execute();
exit($exitCode);
```

## SQL File Format

- One or more SQL statements per file
- Support for comments with `#` or `--`
- Statements must be terminated with `;`
- Empty lines and whitespace are ignored

Example:

```sql
# Create users table
CREATE TABLE users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE
);

-- Add index on email
CREATE INDEX idx_users ON users(email);
```

## Troubleshooting

### "SQL folder not found"

**Cause:** Directory structure doesn't match datasource names

**Solution:**
- Check directory structure: `/migrations/{datasource-name}/`
- Verify datasource name matches folder name

### "Failed to read SQL file"

**Cause:** File permissions or missing file

**Solution:**
- Check file permissions
- Verify file exists and is readable

### Migration not executing

**Cause:** Migrations not enabled

**Solution:**
- Check `migrations.enabled: true` in application.yaml
- Verify SQL files have `.sql` extension
- Check logs for errors

## Technical Details

### Migration Execution Flow

1. Scan `application.yaml` for datasources with `migrations.enabled: true`
2. For each datasource, look for SQL files in `/migrations/{datasource-name}/`
3. Support sub-folders for release-based organization
4. Parse SQL files (supports `#` and `--` style comments)
5. Check if migration already executed (by relative path)
6. Create migration table if not exists
7. Execute SQL statements
8. Record successful execution
9. **Stop on first failure**

### Error Handling

- First SQL failure stops all migrations
- No automatic rollback (statements auto-commit)
- Detailed error logs with stack trace
- Non-zero exit code for automation

### Logging

Uses Winter Boot's logging system:

```
INFO  - Starting SQL migrations execution from: /migrations
INFO  - Processing migrations for datasource: defaultdb
INFO  - Executing migration: 001-init-schema.sql
INFO  - Creating migrations table: winter_migrations_mysql
INFO  - Migration executed successfully: 001-init-schema.sql
INFO  - All SQL migrations executed successfully
```

## Examples

### Standalone Migration Runner

```php
<?php
declare(strict_types=1);

use dev\winterframework\pdbc\migration\SqlMigrationService;

require_once(__DIR__ . '/vendor/autoload.php');

try {
    $appCtx = new \dev\winterframework\core\context\WinterApplicationContext(
        new \dev\winterframework\core\context\ApplicationContextData()
    );
    
    $service = new SqlMigrationService($appCtx, '/path/to/migrations');
    $service->executeMigrations();
    
    echo "SQL migrations completed successfully\n";
    exit(0);
} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

### Integration with WinterBootApplication

```php
#[WinterBootApplication(
    configDirectory: ['/app/config'],
    scanNamespaces: [
        ['App', '/app/src']
    ]
)]
class MyApp {
    
    #[Bean]
    public function getSqlMigrationService(ApplicationContext $appCtx): SqlMigrationService {
        $sqlPath = $appCtx->getProperty('migrations.sqlPath', '/migrations');
        return new SqlMigrationService($appCtx, $sqlPath);
    }
    
    public function onApplicationReady(): void {
        $service = $this->getSqlMigrationService($this->appCtx);
        $service->executeMigrations();
    }
    
    public static function main(): void {
        (new WinterCliApplication())->run(MyApp::class);
    }
}
```

## Notes

- Migration paths are relative from the base SQL folder
- SQL files are executed in alphabetical order
- Multi-tenant data sources require `TenantDataSourceProvider` implementation
- Each database type gets its own migration table (auto-created)
- First SQL failure stops all migrations (no automatic rollback)
