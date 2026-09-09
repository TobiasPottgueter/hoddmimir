# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

ARG NODE_IMAGE=node:24-alpine3.23@sha256:595398b0081eacda8e1c4c5b97b76cd1020e4d58a8ebcb4843b9bca1e79e7436
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.12.7-php8.5-alpine@sha256:214b541fe30aeed2717d54f74149b71b2bdb0089718f1fbb8b43bc170ab00939
ARG FRANKENPHP_BUILDER_IMAGE=dunglas/frankenphp:1.12.7-builder-php8.5-alpine@sha256:862bce4c7efe77337d8f8170a262b639e1787141ffe70ae0edd9ec6ef96c6a99
ARG GOLANG_IMAGE=golang:1.26.8-alpine3.24@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628

FROM ${GOLANG_IMAGE} AS go-toolchain

FROM ${FRANKENPHP_BUILDER_IMAGE} AS frankenphp-build

COPY --from=go-toolchain /usr/local/go /usr/local/go
ENV GOTOOLCHAIN=local
WORKDIR /go/src/app/caddy
COPY docker/web/frankenphp/go.mod docker/web/frankenphp/go.sum ./
RUN go mod download && go mod verify
WORKDIR /go/src/app/caddy/frankenphp
RUN CGO_LDFLAGS="${CGO_LDFLAGS} -Wl,-z,stack-size=0x800000" \
    GOBIN=/usr/local/bin ../../go.sh install -mod=readonly -ldflags "-w -s" \
    && frankenphp version \
    && frankenphp build-info

FROM ${NODE_IMAGE} AS frontend-deps

WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts

FROM frontend-deps AS frontend-source

COPY .editorconfig ./
COPY docs/openapi-v1.json /docs/openapi-v1.json
COPY frontend/ ./

FROM frontend-source AS frontend-test

RUN npm run api:check \
    && npm run lint \
    && npm run typecheck \
    && npm run test:coverage \
    && npm run format:check

FROM frontend-source AS frontend-build

RUN npm run build

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${FRANKENPHP_IMAGE} AS web-runtime

COPY --from=frankenphp-build /usr/local/bin/frankenphp /usr/local/bin/frankenphp

ARG ICU_VERSION=78.1-r0
ARG C_ARES_VERSION=1.34.8-r0
ARG CURL_VERSION=8.22.0-r0
ARG LIBCURL_VERSION=8.22.0-r0
ARG AUTOCONF_VERSION=2.73-r0
ARG DPKG_DEV_VERSION=1.23.7-r0
ARG DPKG_VERSION=1.23.7-r0
ARG FILE_VERSION=5.47-r2
ARG GXX_VERSION=15.2.0-r5
ARG GCC_VERSION=15.2.0-r5
ARG MUSL_DEV_VERSION=1.2.6-r2
ARG MAKE_VERSION=4.4.1-r4
ARG PKGCONF_VERSION=2.5.1-r0
ARG RE2C_VERSION=4.5.1-r0
ARG SU_EXEC_VERSION=0.3-r0
ARG OPENSSL_VERSION=3.5.8-r0

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=UTC \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN apk add --no-cache \
        "c-ares=${C_ARES_VERSION}" \
        "curl=${CURL_VERSION}" \
        "icu-libs=${ICU_VERSION}" \
        "libcurl=${LIBCURL_VERSION}" \
        "libcrypto3=${OPENSSL_VERSION}" \
        "libssl3=${OPENSSL_VERSION}" \
        "openssl=${OPENSSL_VERSION}" \
        "su-exec=${SU_EXEC_VERSION}" \
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
    && php -r "exit(extension_loaded('intl') ? 0 : 1);"

WORKDIR /app

FROM web-runtime AS web-build

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

COPY backend/ ./
COPY --from=frontend-build /frontend/dist/ ./public/

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

FROM web-runtime AS web

COPY --from=web-build --chown=www-data:www-data /app /app
COPY docker/web/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0555 docker/common/app-entrypoint.sh /usr/local/bin/hoddmimir-app-entrypoint
COPY --chmod=0555 docker/common/app-healthcheck.sh /usr/local/bin/hoddmimir-app-healthcheck

RUN if [ -n "$(getcap /usr/local/bin/frankenphp)" ]; then setcap -r /usr/local/bin/frankenphp; fi \
    && mkdir -p var /config/caddy /data/caddy \
    && chown -R www-data:www-data /app/var /config /data \
    && test ! -e /usr/bin/composer \
    && test ! -d /app/tests \
    && test ! -d /app/tools \
    && test ! -e /app/phpunit.coverage-core.xml.dist \
    && test ! -e /app/phpunit.coverage-mariadb.xml.dist \
    && test ! -e /app/phpunit.xml.dist \
    && test ! -e /app/config/services_test.php \
    && test ! -e /app/config/services_e2e.php

ENV HODDMIMIR_RUNTIME_USER=www-data

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD /usr/local/bin/hoddmimir-app-healthcheck wget --quiet --output-document=- http://127.0.0.1:8080/api/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/hoddmimir-app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# Browser E2E is intentionally isolated from the production image. It needs
# Composer's dev autoloader solely so APP_ENV=e2e can bind test-side fakes;
# the production `web` stage above remains --no-dev and cannot load them.
FROM web-runtime AS web-e2e

ENV APP_ENV=e2e \
    APP_DEBUG=0

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY backend/ ./
COPY --from=frontend-build /frontend/dist/ ./public/
COPY docker/web/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0555 docker/common/app-entrypoint.sh /usr/local/bin/hoddmimir-app-entrypoint
COPY --chmod=0555 docker/common/app-healthcheck.sh /usr/local/bin/hoddmimir-app-healthcheck

RUN composer dump-autoload --classmap-authoritative \
    && if [ -n "$(getcap /usr/local/bin/frankenphp)" ]; then setcap -r /usr/local/bin/frankenphp; fi \
    && mkdir -p var /config/caddy /data/caddy \
    && chown -R www-data:www-data /app/var /config /data

ENV HODDMIMIR_RUNTIME_USER=www-data

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD /usr/local/bin/hoddmimir-app-healthcheck wget --quiet --output-document=- http://127.0.0.1:8080/api/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/hoddmimir-app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
