#!/bin/bash

set -euo pipefail

read_secret() {
    local secret_file=$1

    if [[ ! -s "$secret_file" ]]; then
        echo "Required secret file is missing: $secret_file" >&2
        exit 1
    fi

    tr -d '\r\n' < "$secret_file"
}

root_password=$(read_secret /run/secrets/mariadb_root_password)
migration_password=$(read_secret /run/secrets/mariadb_migration_password)
web_password=$(read_secret /run/secrets/mariadb_web_password)
collector_password=$(read_secret /run/secrets/mariadb_collector_password)
backup_worker_password=$(read_secret /run/secrets/mariadb_backup_worker_password)

if [[ ! "$MARIADB_DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "MARIADB_DATABASE contains unsupported characters" >&2
    exit 1
fi

MYSQL_PWD="$root_password" mariadb --protocol=socket --user=root <<SQL
CREATE USER IF NOT EXISTS 'hoddmimir_migration'@'%' IDENTIFIED BY '${migration_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_web'@'%' IDENTIFIED BY '${web_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_collector'@'%' IDENTIFIED BY '${collector_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_backup_worker'@'%' IDENTIFIED BY '${backup_worker_password}';

GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\`.* TO 'hoddmimir_migration'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MARIADB_DATABASE}\`.* TO 'hoddmimir_web'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MARIADB_DATABASE}\`.* TO 'hoddmimir_collector'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MARIADB_DATABASE}\`.* TO 'hoddmimir_backup_worker'@'%';

FLUSH PRIVILEGES;
SQL
