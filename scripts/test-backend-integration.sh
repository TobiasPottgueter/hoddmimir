#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/.." && pwd)
COMPOSE_FILE="$REPOSITORY_ROOT/compose.integration.yaml"
PROJECT_NAME=${HODDMIMIR_INTEGRATION_PROJECT:-hoddmimir-integration-$$}

cleanup() {
    docker compose \
        --project-name "$PROJECT_NAME" \
        --file "$COMPOSE_FILE" \
        down --volumes --remove-orphans >/dev/null
}

finish() {
    primary_status=$?

    trap - 0 1 2 15

    if cleanup; then
        cleanup_status=0
    else
        cleanup_status=$?
    fi

    if [ "$primary_status" -ne 0 ]; then
        if [ "$cleanup_status" -ne 0 ]; then
            printf \
                'Integration command failed with exit status %s; cleanup also failed with exit status %s.\n' \
                "$primary_status" \
                "$cleanup_status" >&2
        else
            printf \
                'Integration command failed with exit status %s; cleanup completed successfully.\n' \
                "$primary_status" >&2
        fi

        exit "$primary_status"
    fi

    if [ "$cleanup_status" -ne 0 ]; then
        printf \
            'Integration command succeeded, but cleanup failed with exit status %s.\n' \
            "$cleanup_status" >&2

        exit "$cleanup_status"
    fi

    exit 0
}

trap finish 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

"$REPOSITORY_ROOT/scripts/init-dev-secrets.sh"

cd "$REPOSITORY_ROOT"

docker compose \
    --project-name "$PROJECT_NAME" \
    --file "$COMPOSE_FILE" \
    up --build --abort-on-container-exit --exit-code-from backend-tests backend-tests
