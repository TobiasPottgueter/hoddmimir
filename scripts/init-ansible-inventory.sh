#!/bin/sh

set -eu

umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
deployment_env=${DEPLOYMENT_ENV_FILE:-"$project_root/.secrets/deployment.env"}
inventory_file=${ANSIBLE_INVENTORY_FILE:-"$project_root/deployment/ansible/inventories/production/hosts.yml"}
inventory_directory=$(dirname -- "$inventory_file")

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

mkdir -p "$inventory_directory"
temporary_file=$(mktemp "$inventory_directory/.hosts.yml.XXXXXX")
trap 'rm -f "$temporary_file"' EXIT HUP INT TERM

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
trap - EXIT HUP INT TERM

printf '%s\n' "Ignored Ansible inventory created at $inventory_file"
