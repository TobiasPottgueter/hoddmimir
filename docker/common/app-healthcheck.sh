#!/bin/sh

set -eu

case "${HODDMIMIR_RUNTIME_USER:-}" in
    app|www-data) ;;
    *) exit 70 ;;
esac

[ -d /run/hoddmimir-secrets ] || exit 70

[ -z "${APP_SECRET_FILE:-}" ] \
    || export APP_SECRET_FILE=/run/hoddmimir-secrets/app_secret
[ -z "${ENCRYPTION_KEY_FILE:-}" ] \
    || export ENCRYPTION_KEY_FILE=/run/hoddmimir-secrets/encryption_key
[ -z "${DATABASE_PASSWORD_FILE:-}" ] \
    || export DATABASE_PASSWORD_FILE=/run/hoddmimir-secrets/database_password
[ -z "${MATRIX_WEBHOOK_URL_FILE:-}" ] \
    || export MATRIX_WEBHOOK_URL_FILE=/run/hoddmimir-secrets/matrix_webhook_url

[ "$#" -gt 0 ] || exit 70
exec su-exec "$HODDMIMIR_RUNTIME_USER:$HODDMIMIR_RUNTIME_USER" "$@"
