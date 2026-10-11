#####################################################################################
#  WinterBoot PHP Image - Run below command (from the repo root)
#
#     ./build/docker/build.sh            # build + push both images (version tag + latest)
#     ./build/docker/build.sh --no-push  # build only
#
#  DB selects the database flavor (one image per database):
#     DB=pgsql (default) -> suvera/winter-boot        pdo_pgsql, pgsql + psql client
#     DB=mysql           -> suvera/winter-boot-mysql  pdo_mysql, mysqli + mysql client
#
#  HOOK_ALL=1 builds the "-hookall" images, for apps that set
#  server.swoole.hook_flags: SWOOLE_HOOK_ALL. Swoole is compiled with:
#     --enable-swoole-curl   native libcurl hook (SWOOLE_HOOK_NATIVE_CURL); without
#                            it HOOK_ALL emulates curl and curl_multi (Guzzle) crashes
#     --enable-swoole-pgsql  pgsql only: PDO-pgsql calls yield to other coroutines
#                            instead of blocking the worker (SWOOLE_HOOK_PDO_PGSQL)
#     DB=pgsql HOOK_ALL=1 -> suvera/winter-boot-hookall
#     DB=mysql HOOK_ALL=1 -> suvera/winter-boot-mysql-hookall
#  pdo_mysql needs no database option: mysqlnd runs on PHP streams, which
#  SWOOLE_HOOK_TCP hooks.
#
######################################################################################
ARG DB=pgsql
ARG HOOK_ALL=0

FROM php:8.5-cli-alpine AS builder
ARG DB
ARG HOOK_ALL

RUN set -eux; \
    case "$DB" in \
        pgsql) dbDevPkgs="postgresql-dev"; dbExts="pdo_pgsql pgsql" ;; \
        mysql) dbDevPkgs=""; dbExts="pdo_mysql mysqli" ;; \
        *) echo "Unsupported DB: $DB (expected pgsql or mysql)" >&2; exit 1 ;; \
    esac; \
    case "$DB:$HOOK_ALL" in \
        *:0) swooleOpts="" ;; \
        pgsql:1) swooleOpts='enable-swoole-curl="yes" enable-swoole-pgsql="yes"' ;; \
        mysql:1) swooleOpts='enable-swoole-curl="yes"' ;; \
        *) echo "Unsupported HOOK_ALL: $HOOK_ALL (expected 0 or 1)" >&2; exit 1 ;; \
    esac; \
    apk add --no-cache \
        $PHPIZE_DEPS \
        pax-utils \
        linux-headers \
        $dbDevPkgs libzip-dev curl-dev openssl-dev librdkafka-dev; \
    docker-php-ext-install -j"$(nproc)" $dbExts pcntl curl zip; \
    pecl install redis; \
    pecl install rdkafka; \
    if [ -n "$swooleOpts" ]; then pecl install -D "$swooleOpts" swoole-6.2.2; else pecl install swoole-6.2.2; fi; \
    pecl install apcu; \
    docker-php-ext-enable redis rdkafka swoole apcu; \
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

# Winter Boot native extension (repo's php-ext/): native AOP interception and
# template evaluation. Pure Zend API, no extra shared-library deps.
# Enabled alongside swoole.
# config.m4 reads the extension version from ../VERSION.txt.
COPY php-ext /tmp/php-ext
COPY VERSION.txt /tmp/VERSION.txt
RUN set -eux; \
    cd /tmp/php-ext; \
    phpize; \
    ./configure --enable-winter_boot; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable winter_boot; \
    php --ri winter_boot; \
    rm -rf /tmp/php-ext /tmp/VERSION.txt

FROM php:8.5-cli-alpine
ARG DB
ARG HOOK_ALL

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /tmp/runtime-pkgs.txt /tmp/runtime-pkgs.txt
COPY build/docker/winter-boot.ini /usr/local/etc/php/conf.d/
# Framework version manifest (read by WinterApplicationRunner::getBootVersion()
# for the startup banner). Mirrors the repo-root location into the image root.
COPY VERSION.txt /VERSION.txt

# DB CLI client: CliSqlFileExecutor runs SQL migrations through psql / mysql.
# Alpine's mysql is MariaDB's client; the /usr/local/bin/mysql wrapper calls it as
# mariadb so every migration does not log a "Deprecated program name" warning.
RUN set -eux; \
    pkgs="$(cat /tmp/runtime-pkgs.txt)"; \
    case "$DB" in \
        pgsql) apk add --no-cache postgresql-client ;; \
        mysql) apk add --no-cache mysql-client mariadb-connector-c; \
               printf '#!/bin/sh\nexec /usr/bin/mariadb "$@"\n' > /usr/local/bin/mysql; \
               chmod 755 /usr/local/bin/mysql ;; \
    esac; \
    if [ -n "$pkgs" ]; then apk add --no-cache $pkgs; fi; \
    rm -f /tmp/runtime-pkgs.txt; \
    php -m | sort; \
    case "$DB" in \
        pgsql) php --ri pdo_pgsql > /dev/null; php --ri pgsql > /dev/null; psql --version ;; \
        mysql) php --ri pdo_mysql > /dev/null; php --ri mysqli > /dev/null; mysql --version ;; \
    esac; \
    php --ri pcntl > /dev/null; \
    php --ri curl > /dev/null; \
    php --ri zip > /dev/null; \
    php --ri redis > /dev/null; \
    php --ri rdkafka > /dev/null; \
    php --ri swoole > /dev/null; \
    php --ri apcu > /dev/null; \
    php --ri winter_boot > /dev/null; \
    if [ "$HOOK_ALL" = "1" ]; then \
        php --ri swoole | grep -q '^curl-native => enabled'; \
        if [ "$DB" = "pgsql" ]; then php --ri swoole | grep -q '^coroutine_pgsql => enabled'; fi; \
        echo "swoole hooks ok"; \
    fi; \
    php -r 'if (ini_get("variables_order") !== "EGPCS") { fwrite(STDERR, "variables_order wrong\n"); exit(1); } if (error_reporting() !== (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)) { fwrite(STDERR, "error_reporting wrong\n"); exit(1); }'; \
    php -r 'if (!apcu_enabled()) { fwrite(STDERR, "apcu not enabled under CLI\n"); exit(1); } echo "apcu ok\n";'; \
    php -r 'if (!function_exists("winter_boot_advise")) { fwrite(STDERR, "winter_boot functions missing\n"); exit(1); } echo "winter_boot ok\n";'
