#!/usr/bin/env bash
# Build the Winter Boot runtime image and push it to Docker Hub
# (https://hub.docker.com/repository/docker/suvera/winter-boot/).
# Tags the image with the version from VERSION.txt plus `latest`.
# Requires an authenticated `docker login` (already done once per machine).
#
# Usage: ./build/docker/build.sh [--no-push]
set -euo pipefail

cd "$(dirname "$0")/../.." # repo root = docker build context

IMAGE="suvera/winter-boot"
VERSION="$(tr -d '[:space:]' < VERSION.txt)"
if [ -z "$VERSION" ]; then
    echo "ERROR: VERSION.txt is empty" >&2
    exit 1
fi

PUSH=1
for arg in "$@"; do
    if [ "$arg" = "--no-push" ]; then
        PUSH=0
    else
        echo "Unknown argument: $arg (only --no-push is supported)" >&2
        exit 1
    fi
done

echo "Building $IMAGE:$VERSION and $IMAGE:latest ..."
docker build . -f ./build/docker/Dockerfile -t "$IMAGE:$VERSION" -t "$IMAGE:latest"

if [ "$PUSH" = "1" ]; then
    echo "Pushing $IMAGE:$VERSION ..."
    docker push "$IMAGE:$VERSION"
    echo "Pushing $IMAGE:latest ..."
    docker push "$IMAGE:latest"
fi

echo "Done: $IMAGE:$VERSION"
