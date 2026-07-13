#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
compose_file="$project_root/compose.e2e.yaml"

compose() {
    docker compose --file "$compose_file" "$@"
}

cleanup() {
    compose down --volumes --remove-orphans >/dev/null
}

collect_failure_diagnostics() {
    if ! compose ps --all >&2; then
        printf 'Could not capture E2E Compose status.\n' >&2
    fi

    if ! compose logs --no-color --timestamps mariadb-e2e webapp-e2e >&2; then
        printf 'Could not capture E2E Compose logs.\n' >&2
    fi
}

finish() {
    primary_status=$?

    trap - 0 1 2 15

    if [ "$primary_status" -ne 0 ]; then
        collect_failure_diagnostics
    fi

    if cleanup; then
        cleanup_status=0
    else
        cleanup_status=$?
    fi

    if [ "$primary_status" -ne 0 ]; then
        if [ "$cleanup_status" -ne 0 ]; then
            printf \
                'E2E command failed with exit status %s; cleanup also failed with exit status %s.\n' \
                "$primary_status" \
                "$cleanup_status" >&2
        else
            printf \
                'E2E command failed with exit status %s; cleanup completed successfully.\n' \
                "$primary_status" >&2
        fi

        exit "$primary_status"
    fi

    if [ "$cleanup_status" -ne 0 ]; then
        printf \
            'E2E command succeeded, but cleanup failed with exit status %s.\n' \
            "$cleanup_status" >&2

        exit "$cleanup_status"
    fi

    exit 0
}

trap finish 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

"$project_root/scripts/init-dev-secrets.sh"
rm -rf "$project_root/frontend/playwright-report" "$project_root/frontend/test-results"
mkdir -p "$project_root/frontend/playwright-report" "$project_root/frontend/test-results"

compose build mariadb-e2e qa-seed webapp-e2e playwright
compose up --detach --wait mariadb-e2e
compose exec -T --user 0 mariadb-e2e /usr/local/bin/hoddmimir-database-user-bootstrap
compose run --rm qa-seed
compose up --detach --wait webapp-e2e
compose run --rm playwright
