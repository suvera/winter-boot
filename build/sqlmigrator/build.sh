#!/bin/bash

cd "$(dirname "$0")"
BOX_EXEC="/home/nrama/.config/composer/vendor/bin/box"

echo "Building PHAR..."

$BOX_EXEC compile

if [ $? -eq 0 ]; then
    echo "Build successful: target/winter-migrations-app.phar"
else
    echo "Build failed!"
    exit 1
fi
