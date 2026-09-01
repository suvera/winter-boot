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
# Using PHAR (built with build/sqlmigrator/build.sh)
./winter-migrations-app.phar -c /path/to/config --sqlPath /path/to/migrations

# Using PHP CLI directly
php bin/sql-migrate.php -c /path/to/config --sqlPath /path/to/migrations
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

### Enable Migrations for Multi-Tenant Datasources

```yaml
multitenant-datasource:
    -   name: tenantdb
        migrations:
            enabled: true
```

### Native CLI Execution (`useCli`)

For complex SQL migration files containing transactions (`BEGIN;`, `COMMIT;`), PL/SQL or T-SQL blocks, stored procedures, triggers, or mixed DDL/DML, you can enable native CLI execution. When `useCli: true` is set, the framework executes the entire SQL file directly via the database's native command-line client (`psql`, `mysql`, `sqlite3`, `sqlplus`, or `sqlcmd`) instead of splitting statements in PHP.

```yaml
datasource:
    -   name: defaultdb
        url: "pgsql:host=localhost;dbname=myapp"
        username: "postgres"
        password: "secretpassword"
        migrations:
            enabled: true
            useCli: true
```

### Installing Native Database CLI Tools on Ubuntu

To install the primary native command-line clients (`psql`, `mysql`, `sqlite3`) on Ubuntu in a single command, run:

```bash
sudo apt-get update && sudo apt-get install -y postgresql-client default-mysql-client sqlite3
```

> **Note:** For Oracle (`sqlplus`) or Microsoft SQL Server (`sqlcmd`), please follow the official Oracle or Microsoft repository installation guides for Ubuntu.

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

> **Note:** The table is automatically created for each database type with appropriate syntax (MySQL, PostgreSQL, SQLite, Oracle, SQL Server).

## Build PHAR

The PHAR can be built using the `build.sh` script:

```bash
cd build/sqlmigrator
./build.sh
```

Output: `build/sqlmigrator/target/winter-migrations-app.phar`

## Usage

### PHAR Package

```bash
# Using PHAR
./winter-migrations-app.phar -c /path/to/config --sqlPath /path/to/migrations
```

### Init Container (Kubernetes)

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: my-app
spec:
  initContainers:
    - name: sql-migrations
      image: my-app:latest
      command: ["/winter-migrations-app.phar"]
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

### Direct Command

```bash
php bin/sql-migrate.php -c /path/to/config --sqlPath /path/to/migrations
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

## Migration Execution

The framework tracks executed migrations and skips them on subsequent runs:

**Example Output:**
```
2026-09-01 11:14:49,914212 [INFO] - d.w.m.SqlMigrationService Starting SQL migrations execution from: /path/to/migrations
2026-09-01 11:14:49,914272 [INFO] - d.w.m.SqlMigrationService Processing migrations for datasource: default
2026-09-01 11:14:49,929826 [INFO] - d.w.m.SqlMigrationService Skipped 3 already executed migration(s)
2026-09-01 11:14:49,929863 [INFO] - d.w.m.SqlMigrationService All SQL migrations executed successfully
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
- `Starting SQL migrations execution from: /migrations`
- `Processing migrations for datasource: defaultdb`
- `Executing migration: 001-init-schema.sql`
- `Creating migrations table: winter_migrations`
- `Migration executed successfully: 001-init-schema.sql`
- `Skipped X already executed migration(s)` (when applicable)
- `All SQL migrations executed successfully`

## Notes

- Migration paths are relative from the base SQL folder
- SQL files are executed in alphabetical order
- Multi-tenant data sources require `TenantDataSourceProvider` implementation
- Each database type gets its own migration table (auto-created)
- First SQL failure stops all migrations (no automatic rollback)
