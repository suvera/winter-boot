#####################################################################################
#  WinterBoot PHP Image - Run below command
#
#     docker build . -t suvera/winter-boot:latest -f ./build/docker/Dockerfile
#
######################################################################################
FROM php:latest

RUN apt update \
    && apt install -y librdkafka-dev libzip-dev procps \
    && pecl install redis \
    && pecl install ev \
    && pecl install rdkafka \
    && pecl install swoole \
    && pecl install zip \
    && pecl install xhprof \
    && docker-php-ext-enable redis ev rdkafka swoole zip xhprof

