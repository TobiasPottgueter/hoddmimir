#!/bin/sh

set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
subject="$repository_root/scripts/init-ansible-lab-inventory.sh"
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-lab-inventory.XXXXXX")
trap 'rm -rf -- "$temporary_root"' EXIT HUP INT TERM

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

deployment_env="$temporary_root/deployment.env"
inventory="$temporary_root/inventory/hosts.yml"
main="$temporary_root/inventory/group_vars/hoddmimir_hosts/main.yml"
production_main="$temporary_root/production/main.yml"
mkdir -p "$(dirname -- "$main")"
{
    printf '%s\n' 'DEPLOYMENT_HOST=lab-host.example.invalid'
    printf '%s\n' 'DEPLOYMENT_USER=root'
    printf '%s\n' 'HODDMIMIR_LAB_DOCKER_SUBNET=172.31.252.0/24'
    printf '%s\n' 'HODDMIMIR_LAB_DOCKER_GATEWAY=172.31.252.1'
    printf '%s\n' 'HODDMIMIR_LAB_WEB_PORT=18080'
} > "$deployment_env"
chmod 0600 "$deployment_env"

run_initializer() {
    LAB_DEPLOYMENT_ENV_FILE="$deployment_env" \
    ANSIBLE_LAB_INVENTORY_FILE="$inventory" \
    ANSIBLE_LAB_GROUP_VARS_FILE="$main" \
    ANSIBLE_PRODUCTION_GROUP_VARS_FILE="$production_main" \
    "$subject" >/dev/null
}

run_initializer
grep -q 'ansible_host: "lab-host.example.invalid"' "$inventory" || fail 'lab host missing'
grep -q '^hoddmimir_deployment_profile: lab$' "$main" || fail 'lab profile missing'
grep -q '^hoddmimir_manage_https: false$' "$main" || fail 'HTTPS was not disabled'
grep -q '^hoddmimir_project_name: hoddmimir-lab$' "$main" || fail 'lab project is not isolated'
grep -q '^hoddmimir_database_name: hoddmimir_lab$' "$main" || fail 'lab database is not isolated'
grep -q '^hoddmimir_image_repository: ""$' "$main" || fail 'image repository placeholder missing'
grep -q '^hoddmimir_registry_index_digests: {}$' "$main" || fail 'registry digest evidence placeholder missing'
grep -q '^hoddmimir_web_port: 18080$' "$main" || fail 'lab port missing'
grep -q '^hoddmimir_backup_execution_required_ack: ENABLE_LAB_BACKUPS$' "$main" || fail 'lab acknowledgement missing'

"${PYTHON_BIN:-python3}" - "$deployment_env" "$inventory" "$main" <<'PY'
import os
import stat
import sys
for name in sys.argv[1:]:
    mode = stat.S_IMODE(os.lstat(name).st_mode)
    if mode != 0o600 or os.path.islink(name):
        raise SystemExit(1)
PY

printf '%s\n' '# operator-owned digest pins' >> "$main"
before=$(cksum "$main")
run_initializer
[ "$(cksum "$main")" = "$before" ] || fail 'existing lab group vars were overwritten'

sed 's/HODDMIMIR_LAB_WEB_PORT=18080/HODDMIMIR_LAB_WEB_PORT=8080/' "$deployment_env" > "$temporary_root/invalid.env"
if LAB_DEPLOYMENT_ENV_FILE="$temporary_root/invalid.env" ANSIBLE_LAB_INVENTORY_FILE="$inventory" ANSIBLE_LAB_GROUP_VARS_FILE="$main" "$subject" >/dev/null 2>&1; then
    fail 'the production port was accepted for the lab'
fi

mkdir -p "$(dirname -- "$production_main")"
sed \
    -e 's/HODDMIMIR_LAB_DOCKER_SUBNET=172.31.252.0\/24/HODDMIMIR_LAB_DOCKER_SUBNET=172.31.250.0\/24/' \
    -e 's/HODDMIMIR_LAB_DOCKER_GATEWAY=172.31.252.1/HODDMIMIR_LAB_DOCKER_GATEWAY=172.31.250.1/' \
    -e 's/HODDMIMIR_LAB_WEB_PORT=18080/HODDMIMIR_LAB_WEB_PORT=18081/' \
    "$deployment_env" > "$temporary_root/noncolliding-candidate.env"
deployment_env="$temporary_root/noncolliding-candidate.env"
{
    printf '%s\n' 'hoddmimir_docker_subnet: "172.31.252.0/24"'
    printf '%s\n' 'hoddmimir_web_port: 19090'
} > "$production_main"
chmod 0600 "$production_main"
if run_initializer >/dev/null 2>&1; then
    fail 'the existing ignored lab network was not checked against production'
fi
{
    printf '%s\n' 'hoddmimir_docker_subnet: "172.31.249.0/24"'
    printf '%s\n' 'hoddmimir_web_port: 18080'
} > "$production_main"
if run_initializer >/dev/null 2>&1; then
    fail 'the existing ignored lab port was not checked against production'
fi
rm -f "$production_main"

rm -f "$inventory"
ln -s "$deployment_env" "$inventory"
if run_initializer >/dev/null 2>&1; then
    fail 'a symlink inventory was accepted'
fi

printf '%s\n' 'Lab inventory initializer tests passed.'
