#####################################################################################
#  WinterBoot PHP Image - Run below command (from the repo root)
#
#     ./build/docker/build.sh            # build + push (version tag + latest)
#     ./build/docker/build.sh --no-push  # build only
#
######################################################################################
FROM php:8.5-cli-alpine AS builder

RUN set -eux; \
    apk add --no-cache \
        $PHPIZE_DEPS \
        pax-utils \
        linux-headers \
        postgresql-dev libzip-dev curl-dev openssl-dev librdkafka-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl curl zip; \
    pecl install redis; \
    pecl install rdkafka; \
    pecl install swoole-6.2.2; \
    docker-php-ext-enable redis rdkafka swoole; \
    EXT_DIR="$(php-config --extension-dir)"; \
    echo "extension dir: $EXT_DIR"; \
    ls "$EXT_DIR"/*.so; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
            | tr ',' '\n' \
            | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    echo "runtime deps:"; echo "$runDeps"; \
    test -n "$runDeps"; \
    echo "$runDeps" > /tmp/runtime-pkgs.txt

# Winter Boot native extension (repo's php-ext/): deferred() callbacks plus
# future native capabilities. Pure Zend API, no extra shared-library deps.
# Enabled alongside swoole — the deferred()/defered() names do not clash.
COPY php-ext /tmp/php-ext
RUN set -eux; \
    cd /tmp/php-ext; \
    phpize; \
    ./configure --enable-winter_boot; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable winter_boot; \
    php --ri winter_boot; \
    rm -rf /tmp/php-ext

FROM php:8.5-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /tmp/runtime-pkgs.txt /tmp/runtime-pkgs.txt
COPY build/docker/winter-boot.ini /usr/local/etc/php/conf.d/
# Framework version manifest (read by WinterApplicationRunner::getBootVersion()
# for the startup banner). Mirrors the repo-root location into the image root.
COPY VERSION.txt /VERSION.txt

RUN set -eux; \
    pkgs="$(cat /tmp/runtime-pkgs.txt)"; \
    apk add --no-cache postgresql-client; \
    if [ -n "$pkgs" ]; then apk add --no-cache $pkgs; fi; \
    rm -f /tmp/runtime-pkgs.txt; \
    php -m | sort; \
    php --ri pdo_pgsql > /dev/null; \
    php --ri pgsql > /dev/null; \
    php --ri pcntl > /dev/null; \
    php --ri curl > /dev/null; \
    php --ri zip > /dev/null; \
    php --ri redis > /dev/null; \
    php --ri rdkafka > /dev/null; \
    php --ri swoole > /dev/null; \
    php --ri winter_boot > /dev/null; \
    php -r 'if (ini_get("variables_order") !== "EGPCS") { fwrite(STDERR, "variables_order wrong\n"); exit(1); } if (error_reporting() !== (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)) { fwrite(STDERR, "error_reporting wrong\n"); exit(1); }'; \
    php -r 'function winterBootSmoke() { deferred(function () { echo "winter_boot deferred() ok\n"; }); } winterBootSmoke();'
