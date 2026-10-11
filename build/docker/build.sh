#!/usr/bin/env bash
# Build the Winter Boot runtime images and push them to Docker Hub:
#   suvera/winter-boot        PostgreSQL flavor (pdo_pgsql, pgsql, psql client)
#   suvera/winter-boot-mysql  MySQL flavor (pdo_mysql, mysqli, mysql client)
# plus a "-hookall" variant of each (suvera/winter-boot-hookall,
# suvera/winter-boot-mysql-hookall): Swoole built with native curl and the pgsql
# coroutine driver, for apps running with hook_flags: SWOOLE_HOOK_ALL.
# Tags each image with the version from VERSION.txt plus `latest`.
# Requires an authenticated `docker login` (already done once per machine).
#
# Usage: ./build/docker/build.sh [--no-push] [--db=pgsql|mysql|all] [--hookall=0|1|all]
# With no arguments it builds and pushes all four images.
set -euo pipefail

cd "$(dirname "$0")/../.." # repo root = docker build context

VERSION="$(tr -d '[:space:]' < VERSION.txt)"
if [ -z "$VERSION" ]; then
    echo "ERROR: VERSION.txt is empty" >&2
    exit 1
fi

PUSH=1
DBS="pgsql mysql"
HOOKS="0 1"
for arg in "$@"; do
    case "$arg" in
        --no-push) PUSH=0 ;;
        --db=pgsql|--db=mysql) DBS="${arg#--db=}" ;;
        --db=all) DBS="pgsql mysql" ;;
        --hookall=0|--hookall=1) HOOKS="${arg#--hookall=}" ;;
        --hookall=all) HOOKS="0 1" ;;
        *)
            echo "Unknown argument: $arg (supported: --no-push, --db=pgsql|mysql|all, --hookall=0|1|all)" >&2
            exit 1
            ;;
    esac
done

# Build every image first and push only when all builds succeeded, so a failed
# build never leaves Docker Hub with a partially released set.
IMAGES=()
for DB in $DBS; do
for HOOK_ALL in $HOOKS; do
    case "$DB" in
        pgsql) IMAGE="suvera/winter-boot" ;;
        mysql) IMAGE="suvera/winter-boot-mysql" ;;
    esac
    if [ "$HOOK_ALL" = "1" ]; then
        IMAGE="$IMAGE-hookall"
    fi

    echo "Building $IMAGE:$VERSION and $IMAGE:latest ..."
    docker build . -f ./build/docker/Dockerfile --build-arg DB="$DB" --build-arg HOOK_ALL="$HOOK_ALL" \
        -t "$IMAGE:$VERSION" -t "$IMAGE:latest"
    IMAGES+=("$IMAGE")
done
done

if [ "$PUSH" = "1" ]; then
    for IMAGE in "${IMAGES[@]}"; do
        echo "Pushing $IMAGE:$VERSION ..."
        docker push "$IMAGE:$VERSION"
        echo "Pushing $IMAGE:latest ..."
        docker push "$IMAGE:latest"
    done
fi

for IMAGE in "${IMAGES[@]}"; do
    echo "Done: $IMAGE:$VERSION"
done
