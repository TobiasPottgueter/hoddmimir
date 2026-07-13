#!/bin/sh

set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
worker_image=${HODDMIMIR_WORKER_SECRET_TEST_IMAGE:-hoddmimir-worker-secret-test:local}
web_image=${HODDMIMIR_WEB_SECRET_TEST_IMAGE:-hoddmimir-web-secret-test:local}
secret_volume="hoddmimir-secret-staging-test-$$"
health_container="hoddmimir-secret-health-test-$$"

cleanup() {
    docker rm --force "$health_container" >/dev/null 2>&1 || true
    docker volume rm --force "$secret_volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker build --target worker --tag "$worker_image" --file "$repository_root/docker/php/Dockerfile" "$repository_root"
docker build --target web --tag "$web_image" --file "$repository_root/docker/web/Dockerfile" "$repository_root"

docker volume create "$secret_volume" >/dev/null
docker run --rm --volume "$secret_volume:/secrets" alpine:3.23 sh -eu -c '
    umask 077
    printf "%s\n" "app-secret-value" > /secrets/app_secret
    printf "%s\n" "encryption-key-value" > /secrets/encryption_key
    printf "%s\n" "database-password-value" > /secrets/database_password
    printf "%s\n" "matrix-webhook-value" > /secrets/matrix_webhook_url
    : > /secrets/empty
    dd if=/dev/zero of=/secrets/oversized bs=65537 count=1 status=none
    mkfifo /secrets/fifo
    ln -s app_secret /secrets/symlink
    chmod 0600 /secrets/app_secret /secrets/encryption_key /secrets/database_password /secrets/matrix_webhook_url /secrets/empty /secrets/oversized
    chown 12345:12345 /secrets/app_secret /secrets/encryption_key /secrets/database_password /secrets/matrix_webhook_url
'

docker run --rm --volume "$secret_volume:/secrets:ro" alpine:3.23 sh -eu -c '
    for secret in app_secret encryption_key database_password matrix_webhook_url; do
        test "$(stat -c %a "/secrets/$secret")" = 600
        test "$(stat -c %u "/secrets/$secret")" = 12345
    done
'

run_hardened() {
    image=$1
    runtime_user=$2
    app_secret_source=$3
    shift 3
    docker run --rm \
        --read-only \
        --cap-drop ALL \
        --cap-add CHOWN \
        --cap-add DAC_READ_SEARCH \
        --cap-add SETGID \
        --cap-add SETUID \
        --security-opt no-new-privileges:true \
        --tmpfs /tmp:mode=1777 \
        --tmpfs /run/hoddmimir-secrets:mode=0700 \
        --mount "type=volume,source=$secret_volume,target=/run/secrets,readonly" \
        --env "HODDMIMIR_RUNTIME_USER=$runtime_user" \
        --env "APP_SECRET_FILE=$app_secret_source" \
        --env ENCRYPTION_KEY_FILE=/run/secrets/encryption_key \
        --env DATABASE_PASSWORD_FILE=/run/secrets/database_password \
        --env MATRIX_WEBHOOK_URL_FILE=/run/secrets/matrix_webhook_url \
        --entrypoint /usr/local/bin/hoddmimir-app-entrypoint \
        "$image" "$@"
}

run_hardened "$worker_image" app /run/secrets/app_secret \
    sh -eu -c '
        test "$(id -u)" = 10001
        test "$(awk "/^CapEff:/ { print \$2 }" /proc/self/status)" = 0000000000000000
        test "$APP_SECRET_FILE" = /run/hoddmimir-secrets/app_secret
        test "$ENCRYPTION_KEY_FILE" = /run/hoddmimir-secrets/encryption_key
        test "$DATABASE_PASSWORD_FILE" = /run/hoddmimir-secrets/database_password
        test "$MATRIX_WEBHOOK_URL_FILE" = /run/hoddmimir-secrets/matrix_webhook_url
        for secret in app_secret encryption_key database_password matrix_webhook_url; do
            test "$(stat -c %a "/run/hoddmimir-secrets/$secret")" = 400
            test "$(stat -c %u "/run/hoddmimir-secrets/$secret")" = 10001
        done
        test "$(cat "$APP_SECRET_FILE")" = app-secret-value
        test "$(cat "$ENCRYPTION_KEY_FILE")" = encryption-key-value
        test "$(cat "$DATABASE_PASSWORD_FILE")" = database-password-value
        test "$(cat "$MATRIX_WEBHOOK_URL_FILE")" = matrix-webhook-value
        test ! -r /run/secrets/app_secret
        ! env | grep -F app-secret-value
        ! env | grep -F database-password-value
    '

# The one-shot schema migration uses the same worker image, but receives only
# its exact mounted subset.
docker run --rm \
    --read-only \
    --cap-drop ALL \
    --cap-add CHOWN \
    --cap-add DAC_READ_SEARCH \
    --cap-add SETGID \
    --cap-add SETUID \
    --security-opt no-new-privileges:true \
    --tmpfs /tmp:mode=1777 \
    --tmpfs /run/hoddmimir-secrets:mode=0700 \
    --mount "type=volume,source=$secret_volume,target=/run/secrets,readonly" \
    --env HODDMIMIR_RUNTIME_USER=app \
    --env APP_SECRET_FILE=/run/secrets/app_secret \
    --env DATABASE_PASSWORD_FILE=/run/secrets/database_password \
    --entrypoint /usr/local/bin/hoddmimir-app-entrypoint \
    "$worker_image" \
    sh -eu -c '
        test "$(id -u)" = 10001
        test "$(awk "/^CapEff:/ { print \$2 }" /proc/self/status)" = 0000000000000000
        test -r "$APP_SECRET_FILE"
        test -r "$DATABASE_PASSWORD_FILE"
        test ! -e /run/hoddmimir-secrets/encryption_key
        test ! -e /run/hoddmimir-secrets/matrix_webhook_url
    '

run_hardened "$web_image" www-data /run/secrets/app_secret \
    sh -eu -c '
        test "$(id -u)" = 82
        test "$(awk "/^CapEff:/ { print \$2 }" /proc/self/status)" = 0000000000000000
        test "$(stat -c %a "$APP_SECRET_FILE")" = 400
        test "$(stat -c %u "$APP_SECRET_FILE")" = 82
        test "$(cat "$DATABASE_PASSWORD_FILE")" = database-password-value
        test ! -r /run/secrets/database_password
    '

# Docker runs image healthchecks outside the main process. The helper remaps
# only file paths to the already staged tmpfs and drops its own root identity
# before executing the actual probe.
docker run --detach --name "$health_container" \
    --read-only \
    --cap-drop ALL \
    --cap-add CHOWN \
    --cap-add DAC_READ_SEARCH \
    --cap-add SETGID \
    --cap-add SETUID \
    --security-opt no-new-privileges:true \
    --tmpfs /tmp:mode=1777 \
    --tmpfs /run/hoddmimir-secrets:mode=0700 \
    --mount "type=volume,source=$secret_volume,target=/run/secrets,readonly" \
    --env HODDMIMIR_RUNTIME_USER=app \
    --env APP_SECRET_FILE=/run/secrets/app_secret \
    --env DATABASE_PASSWORD_FILE=/run/secrets/database_password \
    --entrypoint /usr/local/bin/hoddmimir-app-entrypoint \
    "$worker_image" sleep 30 >/dev/null
docker exec --user root "$health_container" \
    /usr/local/bin/hoddmimir-app-healthcheck sh -eu -c '
        test "$(id -u)" = 10001
        test "$(awk "/^CapEff:/ { print \$2 }" /proc/self/status)" = 0000000000000000
        test "$APP_SECRET_FILE" = /run/hoddmimir-secrets/app_secret
        test "$DATABASE_PASSWORD_FILE" = /run/hoddmimir-secrets/database_password
        test "$(cat "$DATABASE_PASSWORD_FILE")" = database-password-value
    '
docker rm --force "$health_container" >/dev/null

expect_failure() {
    expected_fragment=$1
    shift
    output=$(mktemp)
    if "$@" >"$output" 2>&1; then
        rm -f "$output"
        printf 'expected secret staging failure: %s\n' "$expected_fragment" >&2
        exit 1
    fi
    grep -F "$expected_fragment" "$output" >/dev/null
    ! grep -F app-secret-value "$output" >/dev/null
    ! grep -F database-password-value "$output" >/dev/null
    rm -f "$output"
}

expect_failure 'unsupported file-backed variable' \
    docker run --rm \
        --cap-drop ALL --cap-add CHOWN --cap-add DAC_READ_SEARCH --cap-add SETGID --cap-add SETUID \
        --tmpfs /run/hoddmimir-secrets:mode=0700 \
        --mount "type=volume,source=$secret_volume,target=/run/secrets,readonly" \
        --env HODDMIMIR_RUNTIME_USER=app \
        --env UNTRUSTED_FILE=/run/secrets/app_secret \
        --entrypoint /usr/local/bin/hoddmimir-app-entrypoint "$worker_image" true
expect_failure 'APP_SECRET_FILE cannot be measured' \
    docker run --rm \
        --read-only \
        --cap-drop ALL --cap-add CHOWN --cap-add SETGID --cap-add SETUID \
        --security-opt no-new-privileges:true \
        --tmpfs /tmp:mode=1777 \
        --tmpfs /run/hoddmimir-secrets:mode=0700 \
        --mount "type=volume,source=$secret_volume,target=/run/secrets,readonly" \
        --env HODDMIMIR_RUNTIME_USER=app \
        --env APP_SECRET_FILE=/run/secrets/app_secret \
        --entrypoint /usr/local/bin/hoddmimir-app-entrypoint "$worker_image" true
expect_failure 'must name one direct mounted secret' \
    run_hardened "$worker_image" app /run/secrets/subdirectory/value true
expect_failure 'must not reference a symlink' \
    run_hardened "$worker_image" app /run/secrets/symlink true
expect_failure 'must reference a regular file' \
    run_hardened "$worker_image" app /run/secrets/fifo true
expect_failure 'must not be empty' \
    run_hardened "$worker_image" app /run/secrets/empty true
expect_failure 'exceeds the size limit' \
    run_hardened "$worker_image" app /run/secrets/oversized true

printf '%s\n' 'Native Linux application secret staging tests passed.'
