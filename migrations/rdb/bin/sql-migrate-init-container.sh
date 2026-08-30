#!/bin/bash
# SQL Migration Init Container Script
# This script is designed to run as an init container before the main application starts

set -e

echo "=========================================="
echo "SQL Migration Init Container"
echo "=========================================="

# Configuration from environment variables
SQL_PATH="${SQL_MIGRATION_PATH:-/migrations}"
CONFIG_DIR="${CONFIG_DIR:-/app/config}"
PHP_BIN="${PHP_BIN:-php}"

echo "SQL Migration Path: ${SQL_PATH}"
echo "Config Directory: ${CONFIG_DIR}"

# Validate SQL migration path
if [ ! -d "${SQL_PATH}" ]; then
    echo "ERROR: SQL migration path does not exist: ${SQL_PATH}"
    exit 1
fi

echo "Validating SQL migration directory structure..."

# Check if datasource folders exist
for datasource_dir in "${SQL_PATH}"/*/; do
    if [ -d "${datasource_dir}" ]; then
        datasource_name=$(basename "${datasource_dir}")
        echo "  Found datasource folder: ${datasource_name}"
        
        sql_count=$(find "${datasource_dir}" -name "*.sql" -type f | wc -l)
        echo "    - ${sql_count} SQL file(s) found"
    fi
done

echo ""
echo "Checking for PHAR executable..."

# Try to find the PHAR file
PHAR_PATH=""
if [ -f "/app/migrations.phar" ]; then
    PHAR_PATH="/app/migrations.phar"
elif [ -f "/app/bin/migrations.phar" ]; then
    PHAR_PATH="/app/bin/migrations.phar"
elif [ -f "/migrations.phar" ]; then
    PHAR_PATH="/migrations.phar"
else
    echo "ERROR: PHAR file not found. Please ensure migrations.phar is available."
    exit 1
fi

echo "Using PHAR: ${PHAR_PATH}"

# Execute migrations
echo ""
echo "Executing SQL migrations..."
echo "------------------------------------------"

if [ -n "${CONFIG_DIR}" ]; then
    ${PHP_BIN} ${PHAR_PATH} migrate --sql-path "${SQL_PATH}" --config-dir "${CONFIG_DIR}"
else
    ${PHP_BIN} ${PHAR_PATH} migrate --sql-path "${SQL_PATH}"
fi

MIGRATION_EXIT_CODE=$?

echo "------------------------------------------"

if [ ${MIGRATION_EXIT_CODE} -eq 0 ]; then
    echo ""
    echo "=========================================="
    echo "SQL migrations completed successfully!"
    echo "=========================================="
    exit 0
else
    echo ""
    echo "=========================================="
    echo "ERROR: SQL migrations failed with exit code: ${MIGRATION_EXIT_CODE}"
    echo "=========================================="
    exit ${MIGRATION_EXIT_CODE}
fi
