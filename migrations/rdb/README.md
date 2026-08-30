# SQL Migration Application

SQL migration tool for the Winter Boot framework. This application manages database schema migrations by executing SQL files against configured data sources.

## Features

- Automatically discovers and executes SQL migration files
- Supports multiple data sources (MySQL, PostgreSQL, SQLite, Oracle, SQL Server)
- Tracks executed migrations to prevent duplicate execution
- Supports both single-tenant and multi-tenant data sources
- Can be used as a standalone CLI tool or as an init container in Kubernetes/Docker

## Directory Structure

```
migrations/
├── rdb/
│   ├── bin/
│   │   ├── sql-migrate.php              # Main entry point
│   │   └── sql-migrate-init-container.sh # Docker init container script
│   ├── config/
│   │   └── application.yml              # Application configuration
│   └── src/
│       └── svc/
│           └── SqlMigrationService.php  # Migration execution logic
└── {datasource-name}/                   # Database-specific migrations
    ├── 001-create-users.sql
    ├── 002-add-email-column.sql
    └── ...
```

## Configuration

### Enable Migrations

In your `application.yml`, configure your datasource with migration enabled:

```yaml
datasource:
  - name: primary
    url: mysql://user:password@localhost:3306/mydb
    enabled: true
    migrations:
      enabled: true
```

### Migration File Naming

SQL files are executed in alphabetical order. Use a numbering scheme to control execution order:

```
001-initial-schema.sql
002-add-foreign-keys.sql
003-populate-data.sql
```

### SQL File Format

- Standard SQL statements separated by semicolons
- Supports `#` style comments
- Supports `--` style comments
- String literals are properly handled during parsing

Example:
```sql
-- Create users table
CREATE TABLE users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

# Insert default admin user
INSERT INTO users (username) VALUES ('admin');
```

## Usage

### Command Line

Run migrations directly:

```bash
php migrations/rdb/bin/sql-migrate.php
```

### Docker / Kubernetes

Use the init container script:

```bash
# Set environment variables
export SQL_MIGRATION_PATH=/migrations
export CONFIG_DIR=/app/config

# Run init container
php migrations/rdb/bin/sql-migrate.php
```

For Kubernetes, reference the `sql-migrate-init-container.sh` script in your init container configuration.

## Migration Tracking

Migrations are tracked in a table named `winter_migrations` in each database:

| Column | Description |
|--------|-------------|
| id | Auto-incrementing primary key |
| migration_path | Relative path of the SQL file |
| executed_at | Timestamp when migration was executed |
| executed_by | User who executed the migration |

## Database Support

The following databases are supported:

- MySQL 5.7+
- PostgreSQL 9.6+
- SQLite 3.26+
- Oracle 12c+
- SQL Server 2017+

The migration tool automatically creates the tracking table with the appropriate schema for each database type.

## Error Handling

- Migration failures will stop further execution
- Partial migrations are not rolled back automatically
- Review error logs to identify the failing migration
- Manually fix the issue and re-run migrations

## Build

Build a PHAR file for distribution:

```bash
phing phar
```

The PHAR file will be created in `target/phar/`.

## License

MIT License - see LICENSE file for details.
