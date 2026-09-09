#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
deployment_env=${DEPLOYMENT_ENV_FILE:-"$project_root/.secrets/deployment.env"}
inventory_file=${ANSIBLE_INVENTORY_FILE:-"$project_root/deployment/ansible/inventories/production/hosts.yml"}
inventory_directory=$(dirname -- "$inventory_file")
group_vars_file=${ANSIBLE_GROUP_VARS_FILE:-"$project_root/deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/main.yml"}
group_vars_directory=$(dirname -- "$group_vars_file")
python_bin=${PYTHON_BIN:-python3}

if [ ! -f "$deployment_env" ]; then
    printf '%s\n' "Missing local deployment configuration: $deployment_env" >&2
    exit 1
fi

read_setting() {
    setting_name=$1
    sed -n "s/^${setting_name}=//p" "$deployment_env" | tail -n 1 | tr -d '\r'
}

deployment_host=$(read_setting DEPLOYMENT_HOST)
deployment_user=$(read_setting DEPLOYMENT_USER)
deployment_fqdn=$(read_setting DEPLOYMENT_FQDN)
hoddmimir_public_domain=$(read_setting HODDMIMIR_PUBLIC_DOMAIN)
hoddmimir_acme_email=$(read_setting HODDMIMIR_ACME_EMAIL)
hoddmimir_docker_subnet=$(read_setting HODDMIMIR_DOCKER_SUBNET)
hoddmimir_docker_gateway=$(read_setting HODDMIMIR_DOCKER_GATEWAY)

if [ -L "$group_vars_file" ] || { [ -e "$group_vars_file" ] && [ ! -f "$group_vars_file" ]; }; then
    printf '%s\n' "Production group vars must be a regular non-symlink file: $group_vars_file" >&2
    exit 1
fi
if [ -f "$group_vars_file" ]; then
    chmod 0600 "$group_vars_file"
fi

case "$deployment_host" in
    "" | *[!A-Za-z0-9._:%-]*)
        printf '%s\n' "DEPLOYMENT_HOST is missing or contains unsupported characters." >&2
        exit 1
        ;;
esac

case "$deployment_user" in
    "" | *[!A-Za-z0-9_-]*)
        printf '%s\n' "DEPLOYMENT_USER is missing or contains unsupported characters." >&2
        exit 1
        ;;
esac

if [ -z "$deployment_fqdn" ]; then
    case "$deployment_host" in
        *:*) deployment_fqdn=hoddmimir.localdomain ;;
        *[!0-9.]*) deployment_fqdn=$deployment_host ;;
        *) deployment_fqdn=hoddmimir.localdomain ;;
    esac
fi

case "$deployment_fqdn" in
    "" | *[!A-Za-z0-9.-]* | .* | *..* | *.)
        printf '%s\n' "DEPLOYMENT_FQDN is missing or invalid." >&2
        exit 1
        ;;
esac

if [ ! -e "$group_vars_file" ]; then
    if ! "$python_bin" - \
        "$hoddmimir_public_domain" \
        "$hoddmimir_acme_email" \
        "$hoddmimir_docker_subnet" \
        "$hoddmimir_docker_gateway" >/dev/null <<'PY'
import ipaddress
import re
import sys

domain, email, subnet_text, gateway_text = sys.argv[1:]
if re.fullmatch(r"(?=.{1,253}\Z)(?!.*\.\.)(?!-)[a-z0-9-]+(?:\.[a-z0-9-]+)+", domain) is None:
    raise SystemExit(1)
if domain.endswith(".invalid"):
    raise SystemExit(1)
if re.fullmatch(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+", email) is None:
    raise SystemExit(1)
try:
    network = ipaddress.IPv4Network(subnet_text, strict=True)
    gateway = ipaddress.IPv4Address(gateway_text)
except ValueError:
    raise SystemExit(1)
if network.prefixlen != 24 or not network.is_private or gateway not in network:
    raise SystemExit(1)
if gateway in (network.network_address, network.broadcast_address):
    raise SystemExit(1)
if str(network) != subnet_text or str(gateway) != gateway_text:
    raise SystemExit(1)
PY
    then
        printf '%s\n' 'HODDMIMIR_PUBLIC_DOMAIN, HODDMIMIR_ACME_EMAIL, HODDMIMIR_DOCKER_SUBNET, or HODDMIMIR_DOCKER_GATEWAY is missing or invalid.' >&2
        exit 1
    fi
fi

mkdir -p "$inventory_directory"
mkdir -p "$group_vars_directory"
temporary_file=$(mktemp "$inventory_directory/.hosts.yml.XXXXXX")
temporary_group_vars=''
trap 'rm -f "$temporary_file" "$temporary_group_vars"' EXIT HUP INT TERM

{
    printf '%s\n' '---'
    printf '%s\n' 'all:'
    printf '%s\n' '  children:'
    printf '%s\n' '    hoddmimir_hosts:'
    printf '%s\n' '      hosts:'
    printf '%s\n' '        hoddmimir-production:'
    printf '          ansible_host: "%s"\n' "$deployment_host"
    printf '          ansible_user: "%s"\n' "$deployment_user"
    printf '          alpine_base_hoddmimir_fqdn: "%s"\n' "$deployment_fqdn"
} > "$temporary_file"

chmod 0600 "$temporary_file"
mv "$temporary_file" "$inventory_file"

if [ ! -e "$group_vars_file" ]; then
    temporary_group_vars=$(mktemp "$group_vars_directory/.main.yml.XXXXXX")
    {
        printf '%s\n' '---'
        printf '%s\n' '# Real production configuration. This mode-0600 file is ignored by Git.'
        printf '%s\n' 'hoddmimir_image_repository: ""'
        printf '%s\n' 'hoddmimir_registry_index_digests: {}'
        printf '%s\n' 'hoddmimir_data_worker_image: ""'
        printf '%s\n' 'hoddmimir_backup_worker_image: ""'
        printf '%s\n' 'hoddmimir_webapp_image: ""'
        printf '%s\n' 'hoddmimir_mariadb_image: ""'
        printf 'hoddmimir_public_domain: "%s"\n' "$hoddmimir_public_domain"
        printf 'hoddmimir_acme_email: "%s"\n' "$hoddmimir_acme_email"
        printf 'hoddmimir_docker_subnet: "%s"\n' "$hoddmimir_docker_subnet"
        printf 'hoddmimir_docker_gateway: "%s"\n' "$hoddmimir_docker_gateway"
        printf '%s\n' 'hoddmimir_web_port: 8080'
        printf '%s\n' 'hoddmimir_backup_execution_enabled: false'
    } > "$temporary_group_vars"
    chmod 0600 "$temporary_group_vars"
    mv "$temporary_group_vars" "$group_vars_file"
    temporary_group_vars=''
fi
trap - EXIT HUP INT TERM

printf '%s\n' "Ignored Ansible inventory created at $inventory_file"
printf '%s\n' "Ignored production group vars are ready at $group_vars_file"
