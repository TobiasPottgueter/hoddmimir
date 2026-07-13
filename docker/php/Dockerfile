# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

ARG PHP_IMAGE=php:8.5.8-cli-alpine3.23@sha256:ef7b4b275d4b1cf46e813df41cedfd82ded284b2517d3283c9bafb757e9ae7f3
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE} AS php-base

ARG ICU_VERSION=76.1-r1
ARG C_ARES_VERSION=1.34.8-r0
ARG AUTOCONF_VERSION=2.72-r1
ARG DPKG_DEV_VERSION=1.22.21-r0
ARG DPKG_VERSION=1.22.21-r0
ARG FILE_VERSION=5.46-r2
ARG GXX_VERSION=15.2.0-r2
ARG GCC_VERSION=15.2.0-r2
ARG MUSL_DEV_VERSION=1.2.5-r23
ARG MAKE_VERSION=4.4.1-r3
ARG PKGCONF_VERSION=2.5.1-r0
ARG RE2C_VERSION=4.3.1-r0

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
        "c-ares=${C_ARES_VERSION}" \
        "icu-libs=${ICU_VERSION}" \
    && apk add --no-cache --virtual .php-ext-build-deps \
        "autoconf=${AUTOCONF_VERSION}" \
        "dpkg-dev=${DPKG_DEV_VERSION}" \
        "dpkg=${DPKG_VERSION}" \
        "file=${FILE_VERSION}" \
        "g++=${GXX_VERSION}" \
        "gcc=${GCC_VERSION}" \
        "musl-dev=${MUSL_DEV_VERSION}" \
        "make=${MAKE_VERSION}" \
        "pkgconf=${PKGCONF_VERSION}" \
        "re2c=${RE2C_VERSION}" \
        "icu-dev=${ICU_VERSION}" \
    && docker-php-ext-install -j"$(nproc)" intl pcntl pdo_mysql \
    && apk del .php-ext-build-deps \
    && php -r "exit(extension_loaded('curl') && extension_loaded('intl') ? 0 : 1);" \
    && addgroup -g 10001 -S app \
    && adduser -u 10001 -S -D -G app -h /app app

WORKDIR /app

FROM php-base AS backend-coverage-base

ARG XDEBUG_VERSION=3.5.3
ARG LINUX_HEADERS_VERSION=6.16.12-r0

RUN apk add --no-cache --virtual .coverage-build-deps \
        "autoconf=${AUTOCONF_VERSION}" \
        "dpkg-dev=${DPKG_DEV_VERSION}" \
        "dpkg=${DPKG_VERSION}" \
        "file=${FILE_VERSION}" \
        "g++=${GXX_VERSION}" \
        "gcc=${GCC_VERSION}" \
        "musl-dev=${MUSL_DEV_VERSION}" \
        "make=${MAKE_VERSION}" \
        "pkgconf=${PKGCONF_VERSION}" \
        "re2c=${RE2C_VERSION}" \
        "linux-headers=${LINUX_HEADERS_VERSION}" \
    && pecl install "xdebug-${XDEBUG_VERSION}" \
    && docker-php-ext-enable xdebug \
    && apk del .coverage-build-deps

FROM php-base AS backend-test-runtime

ENV APP_ENV=test \
    APP_DEBUG=1

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY backend/ ./
COPY docs/openapi-v1.json /docs/openapi-v1.json
COPY docs/proxmox-official-cli-baseline.json /docs/proxmox-official-cli-baseline.json
RUN mkdir -p var \
    && chmod 0777 var

FROM backend-test-runtime AS backend-test

RUN composer verify

FROM backend-coverage-base AS backend-coverage-runtime

ENV APP_ENV=test \
    APP_DEBUG=1

COPY --from=backend-test-runtime /app /app
COPY --from=backend-test-runtime /docs/openapi-v1.json /docs/openapi-v1.json
COPY --from=backend-test-runtime /docs/proxmox-official-cli-baseline.json /docs/proxmox-official-cli-baseline.json

FROM backend-coverage-runtime AS backend-mutation-runtime

RUN php vendor/bin/infection --version | grep -F 'version 0.34.0'

FROM php-base AS worker-build

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

COPY backend/ ./

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && rm -rf \
        README.md \
        composer.json \
        composer.lock \
        coverage \
        infection-critical.json5.dist \
        infection.json5.dist \
        phpstan.neon \
        phpstan.neon.dist \
        phpunit.coverage-core.xml.dist \
        phpunit.coverage-mariadb.xml.dist \
        phpunit.integration.xml.dist \
        phpunit.xml.dist \
        config/services_test.php \
        config/services_e2e.php \
        tests \
        tools \
    && test "$(php -r 'require "vendor/autoload.php"; echo (new App\Kernel("prod", false))->getProjectDir();')" = /app

FROM php-base AS worker

COPY --from=worker-build --chown=app:app /app /app

RUN mkdir -p var \
    && chown -R app:app /app/var \
    && test ! -e /usr/bin/composer \
    && test ! -d /app/tests \
    && test ! -d /app/tools \
    && test ! -e /app/phpunit.coverage-core.xml.dist \
    && test ! -e /app/phpunit.coverage-mariadb.xml.dist \
    && test ! -e /app/phpunit.xml.dist \
    && test ! -e /app/config/services_test.php \
    && test ! -e /app/config/services_e2e.php

USER app

ENTRYPOINT ["php", "bin/console"]
