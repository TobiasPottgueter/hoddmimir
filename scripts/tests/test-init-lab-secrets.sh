#!/bin/sh

set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
subject="$repository_root/scripts/init-lab-secrets.sh"
ansible_vault="$repository_root/deployment/ansible/.venv/bin/ansible-vault"
[ -x "$ansible_vault" ] || ansible_vault=$(command -v ansible-vault)
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-lab-secrets.XXXXXX")
trap 'rm -rf -- "$temporary_root"' EXIT HUP INT TERM

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

secrets_root="$temporary_root/secrets"
lab_dir="$secrets_root/lab"
vault="$temporary_root/ansible/group_vars/hoddmimir_hosts/vault.yml"

run_initializer() {
    HODDMIMIR_SECRETS_ROOT="$secrets_root" \
    HODDMIMIR_LAB_SECRETS_DIRECTORY="$lab_dir" \
    HODDMIMIR_LAB_VAULT_FILE="$vault" \
    ANSIBLE_VAULT_BIN="$ansible_vault" \
    "$subject" >/dev/null
}

run_initializer
snapshot=$(find "$lab_dir" -type f -exec cksum {} \; | LC_ALL=C sort)
vault_snapshot=$(cksum "$vault")
run_initializer
[ "$(find "$lab_dir" -type f -exec cksum {} \; | LC_ALL=C sort)" = "$snapshot" ] || fail 'lab secrets were not idempotent'
[ "$(cksum "$vault")" = "$vault_snapshot" ] || fail 'the lab Vault was rewritten'

"${PYTHON_BIN:-python3}" - "$secrets_root" "$lab_dir" "$vault" <<'PY'
import os
import stat
import sys
for directory in sys.argv[1:3]:
    if stat.S_IMODE(os.lstat(directory).st_mode) != 0o700 or os.path.islink(directory):
        raise SystemExit(1)
for directory in sys.argv[2:3]:
    for name in os.listdir(directory):
        path = os.path.join(directory, name)
        if os.path.isfile(path) and (stat.S_IMODE(os.lstat(path).st_mode) != 0o600 or os.path.islink(path)):
            raise SystemExit(1)
if stat.S_IMODE(os.lstat(sys.argv[3]).st_mode) != 0o600 or os.path.islink(sys.argv[3]):
    raise SystemExit(1)
PY

decrypted="$temporary_root/decrypted.yml"
"$ansible_vault" view --vault-password-file "$lab_dir/ansible_vault_password" "$vault" > "$decrypted"
grep -q '^  primaryKeyId: lab_1$' "$decrypted" || fail 'lab key ID missing'
grep -q '^hoddmimir_matrix_webhook_url: https://matrix.invalid/disabled$' "$decrypted" || fail 'disabled Matrix placeholder missing'
if grep -q 'hetzner' "$decrypted"; then
    fail 'the HTTPS-disabled lab Vault contains a DNS token'
fi

sed 's|^hoddmimir_matrix_webhook_url: .*$|hoddmimir_matrix_webhook_url: https://matrix.example.invalid/hook/secret|' "$decrypted" > "$temporary_root/real-webhook.yml"
"$ansible_vault" encrypt --vault-password-file "$lab_dir/ansible_vault_password" --output "$temporary_root/updated-vault" "$temporary_root/real-webhook.yml" >/dev/null
mv "$temporary_root/updated-vault" "$vault"
chmod 0600 "$vault"
run_initializer

"$ansible_vault" view --vault-password-file "$lab_dir/ansible_vault_password" "$vault" > "$temporary_root/current-real.yml"
sed 's|^hoddmimir_matrix_webhook_url: .*$|hoddmimir_matrix_webhook_url: http://matrix.example.invalid/hook/secret|' "$temporary_root/current-real.yml" > "$temporary_root/invalid-webhook.yml"
"$ansible_vault" encrypt --vault-password-file "$lab_dir/ansible_vault_password" --output "$temporary_root/invalid-vault" "$temporary_root/invalid-webhook.yml" >/dev/null
if HODDMIMIR_SECRETS_ROOT="$secrets_root" HODDMIMIR_LAB_SECRETS_DIRECTORY="$lab_dir" HODDMIMIR_LAB_VAULT_FILE="$temporary_root/invalid-vault" ANSIBLE_VAULT_BIN="$ansible_vault" "$subject" >/dev/null 2>&1; then
    fail 'a non-HTTPS Matrix webhook was accepted in the lab Vault'
fi

mkdir -p "$secrets_root/production"
cp "$lab_dir/app_secret" "$secrets_root/production/app_secret"
if run_initializer >/dev/null 2>&1; then
    fail 'lab secret reuse from production was accepted'
fi
rm -f "$secrets_root/production/app_secret"

invalid_root="$temporary_root/invalid"
mkdir -p "$invalid_root/lab"
ln -s "$lab_dir/app_secret" "$invalid_root/lab/app_secret"
if HODDMIMIR_SECRETS_ROOT="$invalid_root" HODDMIMIR_LAB_SECRETS_DIRECTORY="$invalid_root/lab" HODDMIMIR_LAB_VAULT_FILE="$invalid_root/vault.yml" ANSIBLE_VAULT_BIN="$ansible_vault" "$subject" >/dev/null 2>&1; then
    fail 'a symlink lab secret was accepted'
fi

printf '%s\n' 'Lab secret initializer tests passed.'
