#!/bin/bash

set -euo pipefail

readonly secret_directory=/run/secrets
readonly runtime_directory=/run/mysqld
readonly database_socket=/run/mysqld/mysqld.sock
readonly secret_names=(
    mariadb_root_password
    mariadb_migration_password
    mariadb_web_password
    mariadb_collector_password
    mariadb_backup_worker_password
)

client_file=

fail_bootstrap() {
    echo 'MariaDB user bootstrap failed.' >&2
    exit 1
}

cleanup() {
    status=$?
    cleanup_status=0
    trap - EXIT

    if [[ -n "$client_file" ]]; then
        rm -f -- "$client_file" || cleanup_status=1
    fi

    if [[ "$status" -ne 0 ]]; then
        exit "$status"
    fi
    if [[ "$cleanup_status" -ne 0 ]]; then
        echo 'MariaDB user bootstrap cleanup failed.' >&2
        exit 1
    fi
}

read_secret() {
    local secret_name=$1
    local secret_file="$secret_directory/$secret_name"
    local secret_value

    [[ -f "$secret_file" && ! -L "$secret_file" && -s "$secret_file" ]] || fail_bootstrap
    secret_value=$(tr -d '\r\n' < "$secret_file") || fail_bootstrap
    [[ "$secret_value" =~ ^[0-9a-f]{64}$ ]] || fail_bootstrap

    printf '%s' "$secret_value"
}

[[ $(id -u) -eq 0 ]] || fail_bootstrap
[[ -d "$secret_directory" && ! -L "$secret_directory" ]] || fail_bootstrap
[[ -d "$runtime_directory" && ! -L "$runtime_directory" ]] || fail_bootstrap
[[ -S "$database_socket" && ! -L "$database_socket" ]] || fail_bootstrap

database_name=${MARIADB_DATABASE:-}
[[ "$database_name" =~ ^[A-Za-z0-9_]+$ ]] || fail_bootstrap

root_password=$(read_secret mariadb_root_password)
migration_password=$(read_secret mariadb_migration_password)
web_password=$(read_secret mariadb_web_password)
collector_password=$(read_secret mariadb_collector_password)
backup_worker_password=$(read_secret mariadb_backup_worker_password)

umask 077
client_file=$(mktemp "$runtime_directory/.client.XXXXXXXXXX.cnf") || fail_bootstrap
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

printf '%s\n' \
    '[client]' \
    'protocol=socket' \
    "socket=$database_socket" \
    'user=root' \
    "password=$root_password" > "$client_file" || fail_bootstrap
chmod 0600 "$client_file" || fail_bootstrap
[[ $(stat -c '%a:%u:%g' "$client_file") == '600:0:0' ]] || fail_bootstrap

mariadb --defaults-extra-file="$client_file" --batch --skip-column-names <<SQL
SELECT GROUP_CONCAT(CONCAT(QUOTE(User), '@', QUOTE(Host)) SEPARATOR ', ')
INTO @non_local_root_accounts
FROM mysql.user
WHERE User = 'root' AND Host <> 'localhost';
SET @drop_non_local_root_sql = IF(
    @non_local_root_accounts IS NULL,
    'DO 0',
    CONCAT('DROP USER IF EXISTS ', @non_local_root_accounts)
);
PREPARE drop_non_local_root_statement FROM @drop_non_local_root_sql;
EXECUTE drop_non_local_root_statement;
DEALLOCATE PREPARE drop_non_local_root_statement;
CREATE USER IF NOT EXISTS 'hoddmimir_migration'@'%' IDENTIFIED BY '${migration_password}';
ALTER USER 'hoddmimir_migration'@'%' IDENTIFIED BY '${migration_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_web'@'%' IDENTIFIED BY '${web_password}';
ALTER USER 'hoddmimir_web'@'%' IDENTIFIED BY '${web_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_collector'@'%' IDENTIFIED BY '${collector_password}';
ALTER USER 'hoddmimir_collector'@'%' IDENTIFIED BY '${collector_password}';
CREATE USER IF NOT EXISTS 'hoddmimir_backup_worker'@'%' IDENTIFIED BY '${backup_worker_password}';
ALTER USER 'hoddmimir_backup_worker'@'%' IDENTIFIED BY '${backup_worker_password}';
GRANT ALL PRIVILEGES ON \`${database_name}\`.* TO 'hoddmimir_migration'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

unset root_password migration_password web_password collector_password backup_worker_password
