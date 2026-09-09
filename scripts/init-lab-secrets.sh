#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
secrets_root=${HODDMIMIR_SECRETS_ROOT:-"$project_root/.secrets"}
lab_dir=${HODDMIMIR_LAB_SECRETS_DIRECTORY:-"$secrets_root/lab"}
vault_file=${HODDMIMIR_LAB_VAULT_FILE:-"$project_root/deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/vault.yml"}
python_bin=${PYTHON_BIN:-python3}
openssl_bin=${OPENSSL_BIN:-openssl}

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

assert_safe_directory() {
    path=$1
    label=$2
    [ ! -L "$path" ] || fail "$label must not be a symbolic link."
    { [ ! -e "$path" ] || [ -d "$path" ]; } || fail "$label must be a directory."
}

assert_safe_file() {
    path=$1
    label=$2
    [ ! -L "$path" ] || fail "$label must not be a symbolic link."
    { [ ! -e "$path" ] || [ -f "$path" ]; } || fail "$label must be a regular file."
}

assert_safe_directory "$secrets_root" 'The local secrets directory'
mkdir -p "$secrets_root"
chmod 0700 "$secrets_root"
assert_safe_directory "$lab_dir" 'The lab secrets directory'
mkdir -p "$lab_dir"
chmod 0700 "$lab_dir"
assert_safe_directory "$(dirname -- "$vault_file")" 'The lab Vault directory'
mkdir -p "$(dirname -- "$vault_file")"
assert_safe_file "$vault_file" 'The encrypted lab Vault'

temporary_directory=$(mktemp -d "$lab_dir/.initializer.XXXXXX")
chmod 0700 "$temporary_directory"
cleanup() {
    rm -rf -- "$temporary_directory"
}
trap cleanup EXIT HUP INT TERM

new_temporary_file() {
    file=$(mktemp "$temporary_directory/$1.XXXXXX")
    chmod 0600 "$file"
    printf '%s\n' "$file"
}

install_without_overwrite() {
    source=$1
    destination=$2
    if ln "$source" "$destination" 2>/dev/null; then
        rm -f -- "$source"
        return 0
    fi
    return 1
}

validate_hex_file() {
    path=$1
    label=$2
    assert_safe_file "$path" "$label"
    [ -s "$path" ] || fail "$label is empty."
    LC_ALL=C grep -Eq '^[0-9a-f]{64}$' "$path" || fail "$label must contain one lowercase 64-character hexadecimal value."
    [ "$(wc -l < "$path" | tr -d ' ')" -eq 1 ] || fail "$label must contain exactly one line."
    chmod 0600 "$path"
}

create_hex_secret() {
    name=$1
    label=$2
    path="$lab_dir/$name"
    assert_safe_file "$path" "$label"
    if [ ! -e "$path" ]; then
        candidate=$(new_temporary_file "$name")
        "$openssl_bin" rand -hex 32 > "$candidate"
        install_without_overwrite "$candidate" "$path" || rm -f -- "$candidate"
    fi
    validate_hex_file "$path" "$label"
}

for specification in \
    'app_secret:The lab application secret' \
    'mariadb_root_password:The lab MariaDB root password' \
    'mariadb_migration_password:The lab MariaDB migration password' \
    'mariadb_web_password:The lab MariaDB web password' \
    'mariadb_collector_password:The lab MariaDB collector password' \
    'mariadb_backup_worker_password:The lab MariaDB backup-worker password' \
    'local_admin_password:The lab local administrator password' \
    'ansible_vault_password:The lab Ansible Vault password'
do
    name=${specification%%:*}
    label=${specification#*:}
    if [ -e "$lab_dir/$name" ] || [ -L "$lab_dir/$name" ]; then
        validate_hex_file "$lab_dir/$name" "$label"
    fi
done

keyring_file="$lab_dir/encryption_keyring.json"
assert_safe_file "$keyring_file" 'The lab encryption keyring'
if [ ! -e "$keyring_file" ]; then
    candidate=$(new_temporary_file encryption_keyring)
    material=$("$openssl_bin" rand -hex 32)
    printf '{"format":1,"revision":1,"primaryKeyId":"lab_1","keys":[{"id":"lab_1","material":"%s"}]}\n' "$material" > "$candidate"
    unset material
    install_without_overwrite "$candidate" "$keyring_file" || rm -f -- "$candidate"
fi
chmod 0600 "$keyring_file"
if ! "$python_bin" - "$keyring_file" >/dev/null <<'PY'
import json
import re
import sys

try:
    value = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, UnicodeError, json.JSONDecodeError):
    raise SystemExit(1)
if value.get("format") != 1 or value.get("revision") != 1 or value.get("primaryKeyId") != "lab_1":
    raise SystemExit(1)
if set(value) != {"format", "revision", "primaryKeyId", "keys"} or len(value["keys"]) != 1:
    raise SystemExit(1)
entry = value["keys"][0]
if set(entry) != {"id", "material"} or entry["id"] != "lab_1" or re.fullmatch(r"[0-9a-f]{64}", entry["material"]) is None:
    raise SystemExit(1)
PY
then
    fail 'The lab encryption keyring is invalid; it was not replaced.'
fi

create_hex_secret app_secret 'The lab application secret'
create_hex_secret mariadb_root_password 'The lab MariaDB root password'
create_hex_secret mariadb_migration_password 'The lab MariaDB migration password'
create_hex_secret mariadb_web_password 'The lab MariaDB web password'
create_hex_secret mariadb_collector_password 'The lab MariaDB collector password'
create_hex_secret mariadb_backup_worker_password 'The lab MariaDB backup-worker password'
create_hex_secret local_admin_password 'The lab local administrator password'
create_hex_secret ansible_vault_password 'The lab Ansible Vault password'

if ! "$python_bin" - "$lab_dir" "$secrets_root" >/dev/null <<'PY'
import json
import pathlib
import re
import sys

lab = pathlib.Path(sys.argv[1])
root = pathlib.Path(sys.argv[2])
names = (
    "app_secret", "mariadb_root_password", "mariadb_migration_password",
    "mariadb_web_password", "mariadb_collector_password",
    "mariadb_backup_worker_password", "local_admin_password",
    "ansible_vault_password",
)
values = [(lab / name).read_text(encoding="utf-8").strip() for name in names]
values.append(json.loads((lab / "encryption_keyring.json").read_text(encoding="utf-8"))["keys"][0]["material"])
if len(values) != len(set(values)):
    raise SystemExit(1)

other_values = set()
for directory in (root, root / "production"):
    if directory == lab:
        continue
    for name in names:
        path = directory / name
        if path.is_file() and not path.is_symlink():
            value = path.read_text(encoding="utf-8").strip().lower()
            if re.fullmatch(r"[0-9a-f]{64}", value):
                other_values.add(value)
    for keyring_name in ("encryption_key", "encryption_keyring.json"):
        path = directory / keyring_name
        if path.is_file() and not path.is_symlink():
            try:
                other_values.update(str(entry["material"]).lower() for entry in json.loads(path.read_text(encoding="utf-8")).get("keys", []))
            except (OSError, UnicodeError, json.JSONDecodeError, KeyError, TypeError):
                pass
if set(values) & other_values:
    raise SystemExit(1)
PY
then
    fail 'Lab secrets must be unique and must not reuse development or production secret material.'
fi

if [ -n "${ANSIBLE_VAULT_BIN:-}" ]; then
    ansible_vault_bin=$ANSIBLE_VAULT_BIN
elif [ -x "$project_root/deployment/ansible/.venv/bin/ansible-vault" ]; then
    ansible_vault_bin="$project_root/deployment/ansible/.venv/bin/ansible-vault"
else
    ansible_vault_bin=$(command -v ansible-vault || true)
fi
[ -n "$ansible_vault_bin" ] && [ -x "$ansible_vault_bin" ] || fail 'ansible-vault is required to initialize the encrypted lab Vault.'

material=$("$python_bin" - "$keyring_file" <<'PY'
import json
import sys
print(json.load(open(sys.argv[1], encoding="utf-8"))["keys"][0]["material"])
PY
)
expected=$(new_temporary_file vault_plaintext)
{
    printf '%s\n' '---'
    printf 'hoddmimir_app_secret: %s\n' "$(tr -d '\r\n' < "$lab_dir/app_secret")"
    printf '%s\n' 'hoddmimir_encryption_keyring:'
    printf '%s\n' '  format: 1'
    printf '%s\n' '  revision: 1'
    printf '%s\n' '  primaryKeyId: lab_1'
    printf '%s\n' '  keys:'
    printf '%s\n' '    - id: lab_1'
    printf '      material: %s\n' "$material"
    printf 'hoddmimir_mariadb_root_password: %s\n' "$(tr -d '\r\n' < "$lab_dir/mariadb_root_password")"
    printf 'hoddmimir_mariadb_migration_password: %s\n' "$(tr -d '\r\n' < "$lab_dir/mariadb_migration_password")"
    printf 'hoddmimir_mariadb_web_password: %s\n' "$(tr -d '\r\n' < "$lab_dir/mariadb_web_password")"
    printf 'hoddmimir_mariadb_collector_password: %s\n' "$(tr -d '\r\n' < "$lab_dir/mariadb_collector_password")"
    printf 'hoddmimir_mariadb_backup_worker_password: %s\n' "$(tr -d '\r\n' < "$lab_dir/mariadb_backup_worker_password")"
    printf '%s\n' 'hoddmimir_matrix_webhook_url: https://matrix.invalid/disabled'
} > "$expected"
unset material

vault_password_file="$lab_dir/ansible_vault_password"
if [ -e "$vault_file" ]; then
    decrypted=$(new_temporary_file vault_decrypted)
    "$ansible_vault_bin" view --vault-password-file "$vault_password_file" "$vault_file" > "$decrypted" 2>/dev/null \
        || fail 'The existing lab Vault cannot be decrypted with the lab Vault password.'
    matrix_count=$(grep -c '^hoddmimir_matrix_webhook_url:' "$decrypted" || true)
    [ "$matrix_count" -eq 1 ] || fail 'The existing lab Vault has an invalid Matrix webhook field.'
    matrix_url=$(sed -n 's/^hoddmimir_matrix_webhook_url: //p' "$decrypted")
    if ! "$python_bin" - "$matrix_url" >/dev/null <<'PY'
import re
import sys
url = sys.argv[1]
if url == "https://matrix.invalid/disabled":
    raise SystemExit(0)
if len(url) > 2048 or re.fullmatch(r"https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[^\s#]*)?", url) is None:
    raise SystemExit(1)
PY
    then
        fail 'The existing lab Vault contains an invalid Matrix HTTPS webhook.'
    fi
    normalized=$(new_temporary_file vault_normalized)
    sed 's|^hoddmimir_matrix_webhook_url: .*$|hoddmimir_matrix_webhook_url: https://matrix.invalid/disabled|' "$decrypted" > "$normalized"
    cmp -s "$expected" "$normalized" || fail 'The existing lab Vault does not match the generated lab secret set.'
    chmod 0600 "$vault_file"
else
    encrypted=$(new_temporary_file vault_encrypted)
    "$ansible_vault_bin" encrypt --vault-password-file "$vault_password_file" --output "$encrypted" "$expected" >/dev/null 2>&1 \
        || fail 'The encrypted lab Vault could not be created.'
    chmod 0600 "$encrypted"
    install_without_overwrite "$encrypted" "$vault_file" \
        || fail 'The lab Vault appeared concurrently; rerun the initializer to verify it.'
fi

printf '%s\n' 'Lab secrets and the encrypted lab Vault are ready.'
printf '%s\n' "Local secret directory: $lab_dir"
printf '%s\n' "Encrypted Vault: $vault_file"
