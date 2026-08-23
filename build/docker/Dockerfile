#####################################################################################
#  WinterBoot PHP Image - Run below command
#
#     docker build . -t suvera/winter-boot:latest -f ./build/docker/Dockerfile
#
######################################################################################
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y librdkafka-dev libzip-dev procps libssl-dev libcurl4-openssl-dev \
    && pecl install redis \
    && pecl install rdkafka \
    && pecl install swoole-6.2.2 \
    && pecl install zip \
    && docker-php-ext-enable redis rdkafka swoole zip

