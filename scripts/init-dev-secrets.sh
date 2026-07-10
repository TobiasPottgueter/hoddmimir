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

create_secret app_secret
create_secret encryption_key
create_secret mariadb_root_password
create_secret mariadb_migration_password
create_secret mariadb_web_password
create_secret mariadb_collector_password
create_secret mariadb_backup_worker_password

printf '%s\n' "Development secrets are ready in $secrets_dir"
