#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/.." && pwd)
COMPOSE_FILE="$REPOSITORY_ROOT/compose.integration.yaml"
COMPOSE_COVERAGE_FILE="$REPOSITORY_ROOT/compose.integration-coverage.yaml"
PROJECT_NAME=${HODDMIMIR_INTEGRATION_PROJECT:-hoddmimir-integration-$$}
COVERAGE_ENABLED=0
COVERAGE_DIRECTORY="$REPOSITORY_ROOT/backend/coverage"
COVERAGE_PHP_OUTPUT="$COVERAGE_DIRECTORY/integration.cov"
COVERAGE_CLOVER_OUTPUT="$COVERAGE_DIRECTORY/integration.clover.xml"

case $# in
    0)
        ;;
    1)
        if [ "$1" != "--coverage" ]; then
            printf 'Usage: %s [--coverage]\n' "$0" >&2
            exit 2
        fi
        COVERAGE_ENABLED=1
        ;;
    *)
        printf 'Usage: %s [--coverage]\n' "$0" >&2
        exit 2
        ;;
esac

compose() {
    if [ "$COVERAGE_ENABLED" -eq 1 ]; then
        docker compose \
            --project-name "$PROJECT_NAME" \
            --file "$COMPOSE_FILE" \
            --file "$COMPOSE_COVERAGE_FILE" \
            "$@"
    else
        docker compose \
            --project-name "$PROJECT_NAME" \
            --file "$COMPOSE_FILE" \
            "$@"
    fi
}

cleanup() {
    compose down --volumes --remove-orphans >/dev/null
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

if [ "$COVERAGE_ENABLED" -eq 1 ]; then
    if [ -L "$COVERAGE_DIRECTORY" ]; then
        printf 'Refusing to use a symlinked backend coverage directory: %s\n' "$COVERAGE_DIRECTORY" >&2
        exit 2
    fi
    mkdir -p "$COVERAGE_DIRECTORY"
    if [ ! -d "$COVERAGE_DIRECTORY" ] || [ -L "$COVERAGE_DIRECTORY" ]; then
        printf 'Backend coverage path is not a safe directory: %s\n' "$COVERAGE_DIRECTORY" >&2
        exit 2
    fi
    for artifact in "$COVERAGE_PHP_OUTPUT" "$COVERAGE_CLOVER_OUTPUT"; do
        rm -f "$artifact"
        if [ -e "$artifact" ] || [ -L "$artifact" ]; then
            printf 'Could not safely clear integration coverage artifact: %s\n' "$artifact" >&2
            exit 2
        fi
    done
    COVERAGE_UID=$(id -u)
    COVERAGE_GID=$(id -g)
    export COVERAGE_UID COVERAGE_GID
fi

if [ "$COVERAGE_ENABLED" -eq 1 ]; then
    compose up --abort-on-container-exit --exit-code-from backend-tests backend-tests
else
    compose up --build --abort-on-container-exit --exit-code-from backend-tests backend-tests
fi

if [ "$COVERAGE_ENABLED" -eq 1 ]; then
    for artifact in "$COVERAGE_PHP_OUTPUT" "$COVERAGE_CLOVER_OUTPUT"; do
        if [ ! -f "$artifact" ] || [ -L "$artifact" ] || [ ! -s "$artifact" ]; then
            printf 'Integration coverage artifact was not exported safely: %s\n' "$artifact" >&2
            exit 2
        fi
    done
fi
