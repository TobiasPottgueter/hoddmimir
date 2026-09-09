#!/bin/sh

set -eu

script_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_directory/../.." && pwd)
compose_file="$script_directory/fixtures/mariadb-bootstrap/compose.yaml"
temporary_directory=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-mariadb-bootstrap.XXXXXX")
project_name="hoddmimir-mariadb-bootstrap-$$"

cleanup() {
    docker compose --project-name "$project_name" --file "$compose_file" \
        down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$temporary_directory"
}

trap cleanup EXIT HUP INT TERM

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

write_secret() {
    secret_path=$1
    secret_value=$2
    (umask 077 && printf '%s\n' "$secret_value" > "$secret_path")
}

actual_root_password=$(printf '%064d' 0 | tr 0 a)
wrong_root_password=$(printf '%064d' 0 | tr 0 f)
migration_password=$(printf '%064d' 0 | tr 0 b)
web_password=$(printf '%064d' 0 | tr 0 c)
collector_password=$(printf '%064d' 0 | tr 0 d)
backup_password=$(printf '%064d' 0 | tr 0 e)
invalid_root_password='INVALID-ROOT-SECRET-SENTINEL'

write_secret "$temporary_directory/actual-root" "$actual_root_password"
write_secret "$temporary_directory/wrong-root" "$wrong_root_password"
write_secret "$temporary_directory/invalid-root" "$invalid_root_password"
write_secret "$temporary_directory/migration" "$migration_password"
write_secret "$temporary_directory/web" "$web_password"
write_secret "$temporary_directory/collector" "$collector_password"
write_secret "$temporary_directory/backup" "$backup_password"

export HODDMIMIR_REPOSITORY_ROOT="$repository_root"
export HODDMIMIR_ACTUAL_ROOT_SECRET_FILE="$temporary_directory/actual-root"
export HODDMIMIR_WRONG_ROOT_SECRET_FILE="$temporary_directory/wrong-root"
export HODDMIMIR_INVALID_ROOT_SECRET_FILE="$temporary_directory/invalid-root"
export HODDMIMIR_MIGRATION_SECRET_FILE="$temporary_directory/migration"
export HODDMIMIR_WEB_SECRET_FILE="$temporary_directory/web"
export HODDMIMIR_COLLECTOR_SECRET_FILE="$temporary_directory/collector"
export HODDMIMIR_BACKUP_SECRET_FILE="$temporary_directory/backup"

compose() {
    docker compose --project-name "$project_name" --file "$compose_file" "$@"
}

bootstrap() {
    service=$1
    compose exec -T --user 0 "$service" /usr/local/bin/hoddmimir-database-user-bootstrap
}

query() {
    service=$1
    sql=$2
    compose exec -T --user 0 "$service" bash -s -- "$sql" <<'BASH'
set -euo pipefail
sql=$1
client_file=/run/mysqld/.bootstrap-test-audit.cnf
cleanup_audit() {
    rm -f -- "$client_file"
}
trap cleanup_audit EXIT HUP INT TERM
root_password=$(tr -d '\r\n' < /run/secrets/mariadb_actual_root_password)
umask 077
printf '%s\n' \
    '[client]' \
    'protocol=socket' \
    'socket=/run/mysqld/mysqld.sock' \
    'user=root' \
    "password=$root_password" > "$client_file"
unset root_password
mariadb --defaults-extra-file="$client_file" --batch --skip-column-names --execute "$sql"
BASH
}

assert_no_bootstrap_client_file() {
    service=$1
    compose exec -T --user 0 "$service" sh -ec \
        '! find /run/mysqld -maxdepth 1 -type f -name ".client.*.cnf" -print -quit | grep -q .'
}

assert_no_hoddmimir_users() {
    service=$1
    count=$(query "$service" "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'hoddmimir_%';")
    test "$count" = 0 || fail "$service created users after a failed bootstrap"
}

assert_bootstrap_fails_without_secret_leak() {
    service=$1
    set +e
    output=$(bootstrap "$service" 2>&1)
    status=$?
    set -e
    test "$status" -ne 0 || fail "$service bootstrap unexpectedly succeeded"
    case "$output" in
        *'MariaDB user bootstrap failed.'*|*'Access denied for user'*)
            ;;
        *)
            fail "$service did not return a bounded bootstrap failure"
            ;;
    esac
    for secret_value in \
        "$actual_root_password" \
        "$wrong_root_password" \
        "$migration_password" \
        "$web_password" \
        "$collector_password" \
        "$backup_password" \
        "$invalid_root_password"; do
        case "$output" in
            *"$secret_value"*) fail "$service leaked secret material" ;;
        esac
    done
    assert_no_bootstrap_client_file "$service"
    assert_no_hoddmimir_users "$service"
}

# The production Ansible template deliberately has no substitutions. Byte
# identity therefore proves stronger semantic parity than substring checks.
cmp -s \
    "$repository_root/docker/mariadb/init/10-create-app-users.sh" \
    "$repository_root/deployment/ansible/roles/hoddmimir/templates/10-create-app-users.sh.j2" \
    || fail 'Docker bootstrap and rendered Ansible bootstrap logic diverge'

compose build mariadb-valid

compose up --detach --wait mariadb-valid
system_accounts_before=$(query mariadb-valid \
    "SELECT CONCAT(User, '@', Host) FROM mysql.user WHERE User <> 'root' ORDER BY User, Host;")
query mariadb-valid \
    "CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED VIA unix_socket; CREATE USER IF NOT EXISTS 'root'@'10.%' IDENTIFIED VIA unix_socket;" \
    >/dev/null
bootstrap mariadb-valid
assert_no_bootstrap_client_file mariadb-valid
root_hosts=$(query mariadb-valid "SELECT Host FROM mysql.user WHERE User = 'root' ORDER BY Host;")
test "$root_hosts" = localhost || fail "bootstrap exposed root beyond localhost/socket access: $root_hosts"
bootstrap mariadb-valid
assert_no_bootstrap_client_file mariadb-valid

users=$(query mariadb-valid \
    "SELECT CONCAT(User, '@', Host) FROM mysql.user WHERE User LIKE 'hoddmimir_%' ORDER BY User, Host;")
expected_users='hoddmimir_backup_worker@%
hoddmimir_collector@%
hoddmimir_migration@%
hoddmimir_web@%'
test "$users" = "$expected_users" || fail 'bootstrap created an unexpected application-user set'

root_hosts=$(query mariadb-valid "SELECT Host FROM mysql.user WHERE User = 'root' ORDER BY Host;")
test "$root_hosts" = localhost || fail "idempotent bootstrap exposed root beyond localhost/socket access: $root_hosts"
system_accounts_after=$(query mariadb-valid \
    "SELECT CONCAT(User, '@', Host) FROM mysql.user WHERE User <> 'root' AND User NOT LIKE 'hoddmimir_%' ORDER BY User, Host;")
test "$system_accounts_after" = "$system_accounts_before" \
    || fail 'bootstrap changed MariaDB healthcheck or system accounts'

schema_grants=$(query mariadb-valid \
    "SELECT CONCAT(User, '@', Host, ':', Db, ':', Grant_priv) FROM mysql.db WHERE User LIKE 'hoddmimir_%' ORDER BY User, Host, Db;")
test "$schema_grants" = 'hoddmimir_migration@%:hoddmimir_bootstrap_test:Y' \
    || fail "bootstrap created an unexpected schema-grant owner or grant option: $schema_grants"

privileges=$(query mariadb-valid \
    "SELECT GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',') FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = \"'hoddmimir_migration'@'%'\" AND TABLE_SCHEMA = 'hoddmimir_bootstrap_test';")
expected_privileges='ALTER,ALTER ROUTINE,CREATE,CREATE ROUTINE,CREATE TEMPORARY TABLES,CREATE VIEW,DELETE,DELETE HISTORY,DROP,EVENT,EXECUTE,INDEX,INSERT,LOCK TABLES,REFERENCES,SELECT,SHOW CREATE ROUTINE,SHOW VIEW,TRIGGER,UPDATE'
test "$privileges" = "$expected_privileges" \
    || fail "migration user does not have the exact ALL PRIVILEGES schema grant: $privileges"

compose up --detach --wait mariadb-missing-secret
assert_bootstrap_fails_without_secret_leak mariadb-missing-secret

compose up --detach --wait mariadb-invalid-secret
assert_bootstrap_fails_without_secret_leak mariadb-invalid-secret

compose up --detach --wait mariadb-wrong-root
assert_bootstrap_fails_without_secret_leak mariadb-wrong-root

printf 'MariaDB bootstrap container-contract tests passed.\n'
