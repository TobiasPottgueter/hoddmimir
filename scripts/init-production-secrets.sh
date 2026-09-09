#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
secrets_root=${HODDMIMIR_SECRETS_ROOT:-"$project_root/.secrets"}
production_secrets_dir=${HODDMIMIR_PRODUCTION_SECRETS_DIRECTORY:-"$secrets_root/production"}
vault_file=${HODDMIMIR_PRODUCTION_VAULT_FILE:-"$project_root/deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/vault.yml"}
vault_directory=$(dirname -- "$vault_file")
python_bin=${PYTHON_BIN:-python3}
openssl_bin=${OPENSSL_BIN:-openssl}

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

assert_safe_directory() {
    directory=$1
    label=$2

    if [ -L "$directory" ]; then
        fail "$label must not be a symbolic link."
    fi
    if [ -e "$directory" ] && [ ! -d "$directory" ]; then
        fail "$label must be a directory."
    fi
}

assert_safe_file() {
    path=$1
    label=$2

    if [ -L "$path" ]; then
        fail "$label must not be a symbolic link."
    fi
    if [ -e "$path" ] && [ ! -f "$path" ]; then
        fail "$label must be a regular file."
    fi
}

assert_safe_directory "$secrets_root" 'The local secrets directory'
mkdir -p "$secrets_root"
chmod 0700 "$secrets_root"

assert_safe_directory "$production_secrets_dir" 'The production secrets directory'
mkdir -p "$production_secrets_dir"
chmod 0700 "$production_secrets_dir"

assert_safe_directory "$vault_directory" 'The production Vault directory'
mkdir -p "$vault_directory"
assert_safe_file "$vault_file" 'The production Vault file'

temporary_directory=$(mktemp -d "$production_secrets_dir/.initializer.XXXXXX")
chmod 0700 "$temporary_directory"
cleanup() {
    rm -rf -- "$temporary_directory"
}
trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

new_temporary_file() {
    prefix=$1
    temporary_file=$(mktemp "$temporary_directory/${prefix}.XXXXXX")
    chmod 0600 "$temporary_file"
    printf '%s\n' "$temporary_file"
}

install_without_overwrite() {
    source_file=$1
    destination_file=$2

    if ln "$source_file" "$destination_file" 2>/dev/null; then
        rm -f -- "$source_file"
        return 0
    fi

    return 1
}

validate_hex_file() {
    path=$1
    label=$2

    assert_safe_file "$path" "$label"
    if [ ! -s "$path" ] || ! LC_ALL=C grep -Eq '^[0-9a-f]{64}$' "$path" || [ "$(wc -l < "$path" | tr -d ' ')" -ne 1 ]; then
        fail "$label must contain exactly one lowercase 64-character hexadecimal secret."
    fi
    chmod 0600 "$path"
}

create_hex_secret() {
    name=$1
    label=$2
    path="$production_secrets_dir/$name"

    assert_safe_file "$path" "$label"
    if [ ! -e "$path" ]; then
        temporary_file=$(new_temporary_file "$name")
        "$openssl_bin" rand -hex 32 > "$temporary_file"
        if ! install_without_overwrite "$temporary_file" "$path"; then
            rm -f -- "$temporary_file"
        fi
    fi
    validate_hex_file "$path" "$label"
}

validate_keyring() {
    path=$1

    assert_safe_file "$path" 'The production encryption keyring'
    chmod 0600 "$path"
    if ! "$python_bin" - "$path" >/dev/null <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        keyring = json.load(handle)
except (OSError, UnicodeError, json.JSONDecodeError):
    raise SystemExit(1)

if set(keyring) != {"format", "revision", "primaryKeyId", "keys"}:
    raise SystemExit(1)
if keyring["format"] != 1 or keyring["revision"] != 1 or keyring["primaryKeyId"] != "prod_1":
    raise SystemExit(1)
if not isinstance(keyring["keys"], list) or len(keyring["keys"]) != 1:
    raise SystemExit(1)
entry = keyring["keys"][0]
if set(entry) != {"id", "material"} or entry["id"] != "prod_1":
    raise SystemExit(1)
if not isinstance(entry["material"], str) or re.fullmatch(r"[0-9a-f]{64}", entry["material"]) is None:
    raise SystemExit(1)
PY
    then
        fail 'The production encryption keyring is invalid; it was not replaced.'
    fi
}

# Audit every existing initializer-owned entry before creating anything new.
# This makes an invalid partial setup fail before new secret material is added.
for secret_specification in \
    'app_secret:The production application secret' \
    'mariadb_root_password:The production MariaDB root password' \
    'mariadb_migration_password:The production MariaDB migration password' \
    'mariadb_web_password:The production MariaDB web password' \
    'mariadb_collector_password:The production MariaDB collector password' \
    'mariadb_backup_worker_password:The production MariaDB backup-worker password' \
    'local_admin_password:The production local administrator password' \
    'ansible_vault_password:The Ansible Vault password'
do
    secret_name=${secret_specification%%:*}
    secret_label=${secret_specification#*:}
    if [ -e "$production_secrets_dir/$secret_name" ] || [ -L "$production_secrets_dir/$secret_name" ]; then
        validate_hex_file "$production_secrets_dir/$secret_name" "$secret_label"
    fi
done

keyring_file="$production_secrets_dir/encryption_keyring.json"
assert_safe_file "$keyring_file" 'The production encryption keyring'
if [ -e "$keyring_file" ]; then
    validate_keyring "$keyring_file"
fi

matrix_webhook_file="$production_secrets_dir/matrix_webhook_url"
assert_safe_file "$matrix_webhook_file" 'The Matrix webhook setting'
if [ -e "$matrix_webhook_file" ]; then
    chmod 0600 "$matrix_webhook_file"
    if [ "$(tr -d '\r\n' < "$matrix_webhook_file")" != 'https://matrix.invalid/disabled' ]; then
        fail 'The production initializer only accepts the disabled Matrix placeholder; configure a real webhook through a separate explicit maintenance step.'
    fi
fi

hetzner_token_file="$production_secrets_dir/hetzner_dns_api_token"
assert_safe_file "$hetzner_token_file" 'The Hetzner DNS API token'
if [ -e "$hetzner_token_file" ]; then
    chmod 0600 "$hetzner_token_file"
    if ! "$python_bin" - "$hetzner_token_file" >/dev/null <<'PY'
import pathlib
import re
import sys

value = pathlib.Path(sys.argv[1]).read_bytes()
stripped = value.rstrip(b"\r\n")
if value not in (stripped, stripped + b"\n", stripped + b"\r\n"):
    raise SystemExit(1)
if stripped == b"REPLACE_WITH_HETZNER_DNS_API_TOKEN":
    raise SystemExit(0)
if not 32 <= len(stripped) <= 256 or re.fullmatch(rb"[A-Za-z0-9_-]+", stripped) is None:
    raise SystemExit(1)
PY
    then
        fail 'The Hetzner DNS API token file is invalid; it was not replaced.'
    fi
fi

if [ ! -e "$keyring_file" ]; then
    temporary_keyring=$(new_temporary_file encryption_keyring)
    key_material=$("$openssl_bin" rand -hex 32)
    printf '{"format":1,"revision":1,"primaryKeyId":"prod_1","keys":[{"id":"prod_1","material":"%s"}]}\n' \
        "$key_material" > "$temporary_keyring"
    unset key_material
    if ! install_without_overwrite "$temporary_keyring" "$keyring_file"; then
        rm -f -- "$temporary_keyring"
    fi
fi
validate_keyring "$keyring_file"

create_hex_secret app_secret 'The production application secret'
create_hex_secret mariadb_root_password 'The production MariaDB root password'
create_hex_secret mariadb_migration_password 'The production MariaDB migration password'
create_hex_secret mariadb_web_password 'The production MariaDB web password'
create_hex_secret mariadb_collector_password 'The production MariaDB collector password'
create_hex_secret mariadb_backup_worker_password 'The production MariaDB backup-worker password'
create_hex_secret local_admin_password 'The production local administrator password'
create_hex_secret ansible_vault_password 'The Ansible Vault password'

if [ ! -e "$matrix_webhook_file" ]; then
    temporary_matrix=$(new_temporary_file matrix_webhook_url)
    printf '%s\n' 'https://matrix.invalid/disabled' > "$temporary_matrix"
    if ! install_without_overwrite "$temporary_matrix" "$matrix_webhook_file"; then
        rm -f -- "$temporary_matrix"
    fi
fi
chmod 0600 "$matrix_webhook_file"
if [ "$(tr -d '\r\n' < "$matrix_webhook_file")" != 'https://matrix.invalid/disabled' ]; then
    fail 'The production initializer only accepts the disabled Matrix placeholder; configure a real webhook through a separate explicit maintenance step.'
fi

if [ ! -e "$hetzner_token_file" ]; then
    temporary_hetzner_token=$(new_temporary_file hetzner_dns_api_token)
    printf '%s\n' 'REPLACE_WITH_HETZNER_DNS_API_TOKEN' > "$temporary_hetzner_token"
    if ! install_without_overwrite "$temporary_hetzner_token" "$hetzner_token_file"; then
        rm -f -- "$temporary_hetzner_token"
    fi
fi
chmod 0600 "$hetzner_token_file"

if ! "$python_bin" - "$production_secrets_dir" "$secrets_root" >/dev/null <<'PY'
import json
import pathlib
import re
import sys

production = pathlib.Path(sys.argv[1])
root = pathlib.Path(sys.argv[2])
hex_names = (
    "app_secret",
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
    "local_admin_password",
    "ansible_vault_password",
)
values = [(production / name).read_text(encoding="utf-8").strip() for name in hex_names]
keyring = json.loads((production / "encryption_keyring.json").read_text(encoding="utf-8"))
values.append(keyring["keys"][0]["material"])
hetzner_token = (production / "hetzner_dns_api_token").read_text(encoding="utf-8").strip()
if hetzner_token != "REPLACE_WITH_HETZNER_DNS_API_TOKEN":
    values.append(hetzner_token)
if len(values) != len(set(values)):
    raise SystemExit(1)

development_values: set[str] = set()
for name in (
    "app_secret",
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
    "qa_admin_password",
):
    path = root / name
    if path.is_file() and not path.is_symlink():
        value = path.read_text(encoding="utf-8").strip()
        if re.fullmatch(r"[0-9a-fA-F]{64}", value):
            development_values.add(value.lower())
development_keyring = root / "encryption_key"
if development_keyring.is_file() and not development_keyring.is_symlink():
    try:
        parsed = json.loads(development_keyring.read_text(encoding="utf-8"))
        development_values.update(str(entry["material"]).lower() for entry in parsed.get("keys", []))
    except (OSError, UnicodeError, json.JSONDecodeError, KeyError, TypeError):
        pass
if set(values) & development_values:
    raise SystemExit(1)
PY
then
    fail 'Production secrets must be unique and must not reuse development secret material.'
fi

if [ -n "${ANSIBLE_VAULT_BIN:-}" ]; then
    ansible_vault_bin=$ANSIBLE_VAULT_BIN
elif [ -x "$project_root/deployment/ansible/.venv/bin/ansible-vault" ]; then
    ansible_vault_bin="$project_root/deployment/ansible/.venv/bin/ansible-vault"
else
    ansible_vault_bin=$(command -v ansible-vault || true)
fi
if [ -z "$ansible_vault_bin" ] || [ ! -x "$ansible_vault_bin" ]; then
    fail 'ansible-vault is required to initialize the encrypted production Vault.'
fi

key_material=$("$python_bin" - "$keyring_file" <<'PY'
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    print(json.load(handle)["keys"][0]["material"])
PY
)
expected_vault=$(new_temporary_file vault_plaintext)
{
    printf '%s\n' '---'
    printf 'hoddmimir_app_secret: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/app_secret")"
    printf '%s\n' 'hoddmimir_encryption_keyring:'
    printf '%s\n' '  format: 1'
    printf '%s\n' '  revision: 1'
    printf '%s\n' '  primaryKeyId: prod_1'
    printf '%s\n' '  keys:'
    printf '%s\n' '    - id: prod_1'
    printf '      material: %s\n' "$key_material"
    printf 'hoddmimir_mariadb_root_password: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/mariadb_root_password")"
    printf 'hoddmimir_mariadb_migration_password: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/mariadb_migration_password")"
    printf 'hoddmimir_mariadb_web_password: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/mariadb_web_password")"
    printf 'hoddmimir_mariadb_collector_password: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/mariadb_collector_password")"
    printf 'hoddmimir_mariadb_backup_worker_password: %s\n' "$(tr -d '\r\n' < "$production_secrets_dir/mariadb_backup_worker_password")"
    printf '%s\n' 'hoddmimir_matrix_webhook_url: https://matrix.invalid/disabled'
    printf 'hoddmimir_hetzner_dns_api_token: %s\n' "$(tr -d '\r\n' < "$hetzner_token_file")"
} > "$expected_vault"
unset key_material

vault_password_file="$production_secrets_dir/ansible_vault_password"
if [ -e "$vault_file" ]; then
    decrypted_vault=$(new_temporary_file vault_decrypted)
    if ! "$ansible_vault_bin" view --vault-password-file "$vault_password_file" "$vault_file" > "$decrypted_vault" 2>/dev/null; then
        fail 'The existing production Vault cannot be decrypted with the generated Vault password; it was not changed.'
    fi
    if ! cmp -s "$expected_vault" "$decrypted_vault"; then
        legacy_expected_vault=$(new_temporary_file vault_legacy_expected)
        sed '/^hoddmimir_hetzner_dns_api_token:/d' "$expected_vault" > "$legacy_expected_vault"
        previous_name_expected_vault=$(new_temporary_file vault_previous_name_expected)
        sed 's/^hoddmimir_hetzner_dns_api_token:/hoddmimir_hetzner_api_token:/' \
            "$expected_vault" > "$previous_name_expected_vault"
        if ! cmp -s "$legacy_expected_vault" "$decrypted_vault" \
            && ! cmp -s "$previous_name_expected_vault" "$decrypted_vault"; then
            fail 'The existing production Vault does not match the production secret set; it was not changed.'
        fi
        migrated_vault=$(new_temporary_file vault_migrated)
        if ! "$ansible_vault_bin" encrypt --vault-password-file "$vault_password_file" --output "$migrated_vault" "$expected_vault" >/dev/null 2>&1; then
            fail 'The production Vault could not be migrated to include the Hetzner DNS API token.'
        fi
        chmod 0600 "$migrated_vault"
        mv "$migrated_vault" "$vault_file"
    fi
    chmod 0600 "$vault_file"
else
    encrypted_vault=$(new_temporary_file vault_encrypted)
    if ! "$ansible_vault_bin" encrypt --vault-password-file "$vault_password_file" --output "$encrypted_vault" "$expected_vault" >/dev/null 2>&1; then
        fail 'The encrypted production Vault could not be created.'
    fi
    chmod 0600 "$encrypted_vault"
    if ! install_without_overwrite "$encrypted_vault" "$vault_file"; then
        rm -f -- "$encrypted_vault"
        fail 'The production Vault appeared concurrently; rerun the initializer to verify it.'
    fi
fi

printf '%s\n' "Production secrets and the encrypted Ansible Vault are ready."
printf '%s\n' "Local secret directory: $production_secrets_dir"
printf '%s\n' "Encrypted Vault: $vault_file"
