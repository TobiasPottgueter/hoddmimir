#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
secrets_dir="$project_root/.secrets"

mkdir -p "$secrets_dir"

create_secret() {
    secret_file="$secrets_dir/$1"

    if [ -s "$secret_file" ]; then
        return
    fi

    openssl rand -hex 32 > "$secret_file"
}

write_encryption_keyring() {
    key_material=$1
    keyring_file="$secrets_dir/encryption_key"
    temporary_file=$(mktemp "$secrets_dir/.encryption_key.XXXXXX")

    if ! printf '{"format":1,"revision":1,"primaryKeyId":"dev_1","keys":[{"id":"dev_1","material":"%s"}]}\n' \
        "$key_material" > "$temporary_file"; then
        rm -f "$temporary_file"
        return 1
    fi

    if ! chmod 0600 "$temporary_file"; then
        rm -f "$temporary_file"
        return 1
    fi

    if ! mv -f "$temporary_file" "$keyring_file"; then
        rm -f "$temporary_file"
        return 1
    fi
}

create_or_upgrade_encryption_keyring() {
    keyring_file="$secrets_dir/encryption_key"

    if [ ! -s "$keyring_file" ]; then
        write_encryption_keyring "$(openssl rand -hex 32)"
        return
    fi

    legacy_material=$(tr -d '\r\n' < "$keyring_file")
    if printf '%s' "$legacy_material" | grep -Eq '^[0-9A-Fa-f]{64}$'; then
        normalized_material=$(printf '%s' "$legacy_material" | tr 'A-F' 'a-f')
        write_encryption_keyring "$normalized_material"
    fi
}

create_secret app_secret
create_or_upgrade_encryption_keyring
create_secret mariadb_root_password
create_secret mariadb_migration_password
create_secret mariadb_web_password
create_secret mariadb_collector_password
create_secret mariadb_backup_worker_password

printf '%s\n' "Development secrets are ready in $secrets_dir"
