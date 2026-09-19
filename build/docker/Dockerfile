#####################################################################################
#  WinterBoot PHP Image - Run below command
#
#     docker build . -t suvera/winter-boot:latest -f ./build/docker/Dockerfile
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

FROM php:8.5-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /tmp/runtime-pkgs.txt /tmp/runtime-pkgs.txt
COPY build/docker/winter-boot.ini /usr/local/etc/php/conf.d/

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
    php -r 'if (ini_get("variables_order") !== "EGPCS") { fwrite(STDERR, "variables_order wrong\n"); exit(1); } if (error_reporting() !== (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)) { fwrite(STDERR, "error_reporting wrong\n"); exit(1); }'
