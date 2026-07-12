# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=php:8.5.8-cli-alpine3.23@sha256:ef7b4b275d4b1cf46e813df41cedfd82ded284b2517d3283c9bafb757e9ae7f3
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE} AS php-base

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .php-ext-build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install -j"$(nproc)" intl pcntl pdo_mysql \
    && apk del .php-ext-build-deps \
    && php -r "exit(extension_loaded('intl') ? 0 : 1);" \
    && addgroup -g 10001 -S app \
    && adduser -u 10001 -S -D -G app -h /app app

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM php-base AS backend-coverage-base

ARG XDEBUG_VERSION=3.5.3

RUN apk add --no-cache --virtual .coverage-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install "xdebug-${XDEBUG_VERSION}" \
    && docker-php-ext-enable xdebug \
    && apk del .coverage-build-deps

FROM php-base AS backend-test-runtime

ENV APP_ENV=test \
    APP_DEBUG=1

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY backend/ ./
COPY docs/openapi-v1.json /docs/openapi-v1.json
RUN mkdir -p var \
    && chmod 0777 var

FROM backend-test-runtime AS backend-test

RUN composer verify

FROM backend-coverage-base AS backend-coverage-runtime

ENV APP_ENV=test \
    APP_DEBUG=1

COPY --from=backend-test-runtime /app /app

FROM backend-coverage-runtime AS backend-mutation-runtime

RUN php vendor/bin/infection --version | grep -F 'version 0.34.0'

FROM php-base AS worker

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

COPY backend/ ./

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var \
    && chown -R app:app /app/var

USER app

ENTRYPOINT ["php", "bin/console"]
