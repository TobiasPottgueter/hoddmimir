#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
deployment_env=${LAB_DEPLOYMENT_ENV_FILE:-"$project_root/.secrets/lab/deployment.env"}
inventory_file=${ANSIBLE_LAB_INVENTORY_FILE:-"$project_root/deployment/ansible/inventories/lab/hosts.yml"}
group_vars_file=${ANSIBLE_LAB_GROUP_VARS_FILE:-"$project_root/deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/main.yml"}
production_group_vars_file=${ANSIBLE_PRODUCTION_GROUP_VARS_FILE:-"$project_root/deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/main.yml"}
python_bin=${PYTHON_BIN:-python3}

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

assert_regular_file() {
    path=$1
    label=$2
    if [ -L "$path" ] || { [ -e "$path" ] && [ ! -f "$path" ]; }; then
        fail "$label must be a regular non-symlink file."
    fi
}

assert_regular_file "$deployment_env" 'The lab deployment configuration'
[ -f "$deployment_env" ] || fail "Missing local lab deployment configuration: $deployment_env"
chmod 0600 "$deployment_env"

read_setting() {
    setting_name=$1
    sed -n "s/^${setting_name}=//p" "$deployment_env" | tail -n 1 | tr -d '\r'
}

read_yaml_scalar() {
    source_file=$1
    key=$2
    sed -n "s/^${key}:[[:space:]]*//p" "$source_file" \
        | tail -n 1 \
        | sed 's/[[:space:]]*#.*$//; s/^"//; s/"$//; s/^'"'"'//; s/'"'"'$//'
}

deployment_host=$(read_setting DEPLOYMENT_HOST)
deployment_user=$(read_setting DEPLOYMENT_USER)
docker_subnet=$(read_setting HODDMIMIR_LAB_DOCKER_SUBNET)
docker_gateway=$(read_setting HODDMIMIR_LAB_DOCKER_GATEWAY)
web_port=$(read_setting HODDMIMIR_LAB_WEB_PORT)
[ -n "$web_port" ] || web_port=18080

case "$deployment_host" in
    "" | *[!A-Za-z0-9._:%-]*) fail 'DEPLOYMENT_HOST is missing or contains unsupported characters.' ;;
esac
case "$deployment_user" in
    "" | *[!A-Za-z0-9_-]*) fail 'DEPLOYMENT_USER is missing or contains unsupported characters.' ;;
esac

if ! "$python_bin" - "$docker_subnet" "$docker_gateway" "$web_port" >/dev/null <<'PY'
import ipaddress
import sys

subnet_text, gateway_text, port_text = sys.argv[1:]
try:
    network = ipaddress.IPv4Network(subnet_text, strict=True)
    gateway = ipaddress.IPv4Address(gateway_text)
    port = int(port_text)
except ValueError:
    raise SystemExit(1)
if network.prefixlen != 24 or not network.is_private or gateway not in network:
    raise SystemExit(1)
if gateway in (network.network_address, network.broadcast_address):
    raise SystemExit(1)
if str(network) != subnet_text or str(gateway) != gateway_text:
    raise SystemExit(1)
if not 1 <= port <= 65535 or port == 8080 or str(port) != port_text:
    raise SystemExit(1)
PY
then
    fail 'Configure one canonical private lab /24, an in-range gateway, and a non-production TCP port.'
fi

assert_regular_file "$inventory_file" 'The lab inventory'
assert_regular_file "$group_vars_file" 'The lab group vars'

effective_lab_subnet=$docker_subnet
effective_lab_gateway=$docker_gateway
effective_lab_port=$web_port
if [ -f "$group_vars_file" ]; then
    effective_lab_subnet=$(read_yaml_scalar "$group_vars_file" hoddmimir_docker_subnet)
    effective_lab_gateway=$(read_yaml_scalar "$group_vars_file" hoddmimir_docker_gateway)
    effective_lab_port=$(read_yaml_scalar "$group_vars_file" hoddmimir_web_port)
    if ! "$python_bin" - "$effective_lab_subnet" "$effective_lab_gateway" "$effective_lab_port" >/dev/null <<'PY'
import ipaddress
import sys

subnet_text, gateway_text, port_text = sys.argv[1:]
try:
    network = ipaddress.IPv4Network(subnet_text, strict=True)
    gateway = ipaddress.IPv4Address(gateway_text)
    port = int(port_text)
except ValueError:
    raise SystemExit(1)
if network.prefixlen != 24 or not network.is_private or gateway not in network:
    raise SystemExit(1)
if gateway in (network.network_address, network.broadcast_address):
    raise SystemExit(1)
if str(network) != subnet_text or str(gateway) != gateway_text:
    raise SystemExit(1)
if not 1 <= port <= 65535 or port == 8080 or str(port) != port_text:
    raise SystemExit(1)
PY
    then
        fail 'The existing ignored lab main.yml contains an invalid network, gateway, or loopback port.'
    fi
fi

assert_regular_file "$production_group_vars_file" 'The production group vars'
if [ -f "$production_group_vars_file" ]; then
    production_subnet=$(read_yaml_scalar "$production_group_vars_file" hoddmimir_docker_subnet)
    production_port=$(read_yaml_scalar "$production_group_vars_file" hoddmimir_web_port)
    [ -n "$production_port" ] || production_port=8080
    if ! "$python_bin" - "$effective_lab_subnet" "$effective_lab_port" "$production_subnet" "$production_port" >/dev/null <<'PY'
import ipaddress
import sys

lab_subnet, lab_port, production_subnet, production_port = sys.argv[1:]
if lab_port == production_port:
    raise SystemExit(1)
if production_subnet:
    try:
        if ipaddress.IPv4Network(lab_subnet).overlaps(ipaddress.IPv4Network(production_subnet)):
            raise SystemExit(1)
    except ValueError:
        raise SystemExit(1)
PY
    then
        fail 'The lab Docker network and loopback port must not overlap the configured production deployment.'
    fi
fi

mkdir -p "$(dirname -- "$inventory_file")" "$(dirname -- "$group_vars_file")"

inventory_candidate=$(mktemp "$(dirname -- "$inventory_file")/.hosts.yml.XXXXXX")
group_vars_candidate=''
cleanup() {
    rm -f -- "$inventory_candidate" "$group_vars_candidate"
}
trap cleanup EXIT HUP INT TERM

{
    printf '%s\n' '---'
    printf '%s\n' 'all:'
    printf '%s\n' '  children:'
    printf '%s\n' '    hoddmimir_hosts:'
    printf '%s\n' '      hosts:'
    printf '%s\n' '        hoddmimir-lab:'
    printf '          ansible_host: "%s"\n' "$deployment_host"
    printf '          ansible_user: "%s"\n' "$deployment_user"
} > "$inventory_candidate"
chmod 0600 "$inventory_candidate"
mv "$inventory_candidate" "$inventory_file"
inventory_candidate=''

if [ ! -e "$group_vars_file" ]; then
    group_vars_candidate=$(mktemp "$(dirname -- "$group_vars_file")/.main.yml.XXXXXX")
    {
        printf '%s\n' '---'
        printf '%s\n' '# Real isolated lab configuration. This mode-0600 file is ignored by Git.'
        printf '%s\n' 'hoddmimir_deployment_profile: lab'
        printf '%s\n' 'hoddmimir_manage_https: false'
        printf '%s\n' 'hoddmimir_project_name: hoddmimir-lab'
        printf '%s\n' 'hoddmimir_install_directory: /opt/hoddmimir-lab'
        printf '%s\n' 'hoddmimir_secrets_directory: /etc/hoddmimir-lab/secrets'
        printf '%s\n' 'hoddmimir_database_name: hoddmimir_lab'
        printf '%s\n' 'hoddmimir_image_repository: ""'
        printf '%s\n' 'hoddmimir_registry_index_digests: {}'
        printf '%s\n' 'hoddmimir_data_worker_image: ""'
        printf '%s\n' 'hoddmimir_backup_worker_image: ""'
        printf '%s\n' 'hoddmimir_webapp_image: ""'
        printf '%s\n' 'hoddmimir_mariadb_image: ""'
        printf 'hoddmimir_docker_subnet: "%s"\n' "$docker_subnet"
        printf 'hoddmimir_docker_gateway: "%s"\n' "$docker_gateway"
        printf 'hoddmimir_web_port: %s\n' "$web_port"
        printf '%s\n' 'hoddmimir_backup_execution_enabled: false'
        printf '%s\n' 'hoddmimir_backup_execution_required_ack: ENABLE_LAB_BACKUPS'
        printf '%s\n' 'hoddmimir_matrix_notifications_enabled: false'
    } > "$group_vars_candidate"
    chmod 0600 "$group_vars_candidate"
    mv "$group_vars_candidate" "$group_vars_file"
    group_vars_candidate=''
else
    chmod 0600 "$group_vars_file"
fi

trap - EXIT HUP INT TERM
printf '%s\n' "Ignored lab inventory created at $inventory_file"
printf '%s\n' "Ignored lab group vars are ready at $group_vars_file"
