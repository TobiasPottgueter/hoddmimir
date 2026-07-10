# syntax=docker/dockerfile:1.7

ARG NODE_IMAGE=node:24-alpine3.23@sha256:595398b0081eacda8e1c4c5b97b76cd1020e4d58a8ebcb4843b9bca1e79e7436
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5-alpine@sha256:7a80dbeefcd4e9b267ea242b2c4d1c14a968c1e7d493e4455f0587989069cf87

FROM ${NODE_IMAGE} AS frontend-deps

WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts

FROM frontend-deps AS frontend-source

COPY .editorconfig ./
COPY frontend/ ./

FROM frontend-source AS frontend-test

RUN npm run lint \
    && npm run typecheck \
    && npm test \
    && npm run format:check

FROM frontend-source AS frontend-build

RUN npm run build

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${FRANKENPHP_IMAGE} AS web

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=UTC \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

COPY backend/ ./
COPY --from=frontend-build /frontend/dist/ ./public/
COPY docker/web/Caddyfile /etc/caddy/Caddyfile

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p var /config/caddy /data/caddy \
    && chown -R www-data:www-data /app/var /config /data

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD wget --quiet --output-document=- http://127.0.0.1:8080/api/health >/dev/null || exit 1

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
