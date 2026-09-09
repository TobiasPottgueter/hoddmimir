#!/bin/sh

set -eu

readonly staging_directory=/run/hoddmimir-secrets
readonly maximum_secret_bytes=65536

fail() {
    printf 'hoddmimir secret staging failed: %s\n' "$1" >&2
    exit 70
}

case "${HODDMIMIR_RUNTIME_USER:-}" in
    app|www-data) ;;
    *) fail 'invalid runtime identity' ;;
esac

runtime_user=$HODDMIMIR_RUNTIME_USER
runtime_uid=$(id -u "$runtime_user") || fail 'runtime identity is unavailable'
runtime_gid=$(id -g "$runtime_user") || fail 'runtime identity is unavailable'

[ "$(id -u)" -eq 0 ] || fail 'entrypoint must start as root'
[ -d "$staging_directory" ] || fail 'private staging tmpfs is missing'
[ ! -L "$staging_directory" ] || fail 'private staging path must not be a symlink'
[ "$(stat -f -c '%T' "$staging_directory")" = tmpfs ] || fail 'private staging path is not tmpfs'

for environment_name in $(env | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*_FILE\)=.*/\1/p'); do
    case "$environment_name" in
        APP_SECRET_FILE|ENCRYPTION_KEY_FILE|DATABASE_PASSWORD_FILE|MATRIX_WEBHOOK_URL_FILE) ;;
        *) fail "unsupported file-backed variable $environment_name" ;;
    esac
done

umask 077
chmod 0700 "$staging_directory" || fail 'cannot secure private staging tmpfs'
find "$staging_directory" -mindepth 1 -maxdepth 1 -exec rm -f -- {} + \
    || fail 'cannot clear private staging tmpfs'

stage_secret() {
    variable_name=$1
    destination_name=$2
    eval "source_path=\${$variable_name:-}"

    [ -n "$source_path" ] || return 0

    case "$source_path" in
        /run/secrets/*)
            source_name=${source_path#/run/secrets/}
            case "$source_name" in
                ''|.|..|*/*) fail "$variable_name must name one direct mounted secret" ;;
            esac
            ;;
        *) fail "$variable_name must name one direct mounted secret" ;;
    esac

    [ ! -L "$source_path" ] || fail "$variable_name must not reference a symlink"
    [ -f "$source_path" ] || fail "$variable_name must reference a regular file"

    secret_size=$(wc -c < "$source_path") || fail "$variable_name cannot be measured"
    [ "$secret_size" -gt 0 ] || fail "$variable_name must not be empty"
    [ "$secret_size" -le "$maximum_secret_bytes" ] || fail "$variable_name exceeds the size limit"

    temporary_file=$(mktemp "$staging_directory/.${destination_name}.XXXXXX") \
        || fail "$variable_name cannot be staged"
    if ! cp "$source_path" "$temporary_file"; then
        rm -f "$temporary_file"
        fail "$variable_name cannot be staged"
    fi
    chmod 0400 "$temporary_file" || fail "$variable_name permissions cannot be secured"
    chown "$runtime_uid:$runtime_gid" "$temporary_file" \
        || fail "$variable_name ownership cannot be secured"
    mv -f "$temporary_file" "$staging_directory/$destination_name" \
        || fail "$variable_name cannot be published"

    eval "export $variable_name=\$staging_directory/\$destination_name"
}

stage_secret APP_SECRET_FILE app_secret
stage_secret ENCRYPTION_KEY_FILE encryption_key
stage_secret DATABASE_PASSWORD_FILE database_password
stage_secret MATRIX_WEBHOOK_URL_FILE matrix_webhook_url

chown "$runtime_uid:$runtime_gid" "$staging_directory" \
    || fail 'private staging ownership cannot be secured'

[ "$#" -gt 0 ] || fail 'no application command was supplied'
exec su-exec "$runtime_user:$runtime_user" "$@"
