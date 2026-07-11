#!/bin/sh

set -eu

if [ "${HODDMIMIR_MIGRATION_INIT_FAIL:-0}" = "1" ]; then
    exit "${HODDMIMIR_MIGRATION_INIT_FAIL_CODE:-41}"
fi

mkdir -p "$(dirname -- "$0")/../.secrets"
