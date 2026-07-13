#!/bin/sh

set -eu

script_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_directory/../.." && pwd)
subject="$repository_root/scripts/init-production-secrets.sh"
ansible_vault="$repository_root/deployment/ansible/.venv/bin/ansible-vault"
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-production-secrets.XXXXXX")

cleanup() {
    rm -rf "$temporary_root"
}
trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

file_mode() {
    path=$1
    if mode=$(stat -c '%a' "$path" 2>/dev/null); then
        printf '%s\n' "$mode"
    else
        stat -f '%Lp' "$path"
    fi
}

write_manifest() {
    destination=$1
    : > "$destination"
    find "$production_dir" -type f -print | LC_ALL=C sort | while IFS= read -r path; do
        cksum "$path"
    done >> "$destination"
    cksum "$vault_file" >> "$destination"
}

test -x "$ansible_vault" || fail 'the repository Ansible Vault executable is missing'

secrets_root="$temporary_root/.secrets"
production_dir="$secrets_root/production"
vault_file="$temporary_root/ansible/group_vars/hoddmimir_hosts/vault.yml"
mkdir -p "$secrets_root"
printf '%064d\n' 1 > "$secrets_root/app_secret"
printf '%s\n' '{"format":1,"revision":1,"primaryKeyId":"dev_1","keys":[{"id":"dev_1","material":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]}' > "$secrets_root/encryption_key"
chmod 0600 "$secrets_root/app_secret" "$secrets_root/encryption_key"

run_initializer() {
    HODDMIMIR_SECRETS_ROOT="$secrets_root" \
    HODDMIMIR_PRODUCTION_SECRETS_DIRECTORY="$production_dir" \
    HODDMIMIR_PRODUCTION_VAULT_FILE="$vault_file" \
    ANSIBLE_VAULT_BIN="$ansible_vault" \
        "$subject"
}

output=$(run_initializer)

for name in \
    app_secret \
    encryption_keyring.json \
    mariadb_root_password \
    mariadb_migration_password \
    mariadb_web_password \
    mariadb_collector_password \
    mariadb_backup_worker_password \
    local_admin_password \
    ansible_vault_password \
    matrix_webhook_url
do
    path="$production_dir/$name"
    test -f "$path" || fail "$name was not created"
    test ! -L "$path" || fail "$name is a symbolic link"
    test "$(file_mode "$path")" = 600 || fail "$name is not mode 0600"
done
test -f "$vault_file" || fail 'the encrypted Vault was not created'
test "$(file_mode "$vault_file")" = 600 || fail 'the encrypted Vault is not mode 0600'
grep -q '^\$ANSIBLE_VAULT;' "$vault_file" || fail 'the Vault is not encrypted'

for name in app_secret mariadb_root_password mariadb_migration_password mariadb_web_password mariadb_collector_password mariadb_backup_worker_password local_admin_password ansible_vault_password; do
    value=$(tr -d '\r\n' < "$production_dir/$name")
    case "$output" in
        *"$value"*) fail "$name leaked through initializer output" ;;
    esac
done

decrypted="$temporary_root/decrypted.yml"
"$ansible_vault" view --vault-password-file "$production_dir/ansible_vault_password" "$vault_file" > "$decrypted"
grep -q '^hoddmimir_matrix_webhook_url: https://matrix.invalid/disabled$' "$decrypted" \
    || fail 'the disabled Matrix placeholder is missing'
grep -q '^  primaryKeyId: prod_1$' "$decrypted" || fail 'the structured keyring primary is missing'
grep -q '^hoddmimir_app_secret: ' "$decrypted" || fail 'the application secret is missing from the Vault'

before="$temporary_root/before.sha256"
after="$temporary_root/after.sha256"
write_manifest "$before"
run_initializer >/dev/null
write_manifest "$after"
cmp -s "$before" "$after" || fail 'the idempotent rerun changed generated material'

invalid_root="$temporary_root/invalid/.secrets"
invalid_production="$invalid_root/production"
invalid_vault="$temporary_root/invalid/ansible/vault.yml"
mkdir -p "$invalid_production"
printf '%s\n' 'invalid-existing-value' > "$invalid_production/app_secret"
chmod 0600 "$invalid_production/app_secret"
if HODDMIMIR_SECRETS_ROOT="$invalid_root" \
    HODDMIMIR_PRODUCTION_SECRETS_DIRECTORY="$invalid_production" \
    HODDMIMIR_PRODUCTION_VAULT_FILE="$invalid_vault" \
    ANSIBLE_VAULT_BIN="$ansible_vault" \
        "$subject" >/dev/null 2>&1; then
    fail 'an invalid existing production secret was accepted'
fi
test "$(cat "$invalid_production/app_secret")" = 'invalid-existing-value' \
    || fail 'the invalid existing secret was overwritten'
test ! -e "$invalid_vault" || fail 'a Vault was created after validation failed'

printf '%s\n' 'Production secret initializer tests passed.'
