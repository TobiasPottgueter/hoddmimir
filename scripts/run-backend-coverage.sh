#!/bin/sh

set -eu

PROFILE=${1:-all}
case "$PROFILE" in
    all|foundation|core|mariadb|compose) ;;
    *) printf 'Usage: %s [all|foundation|core|mariadb|compose]\n' "$0" >&2; exit 2 ;;
esac

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/.." && pwd)
COVERAGE_DIRECTORY="$REPOSITORY_ROOT/backend/coverage"
FOUNDATION_ARCHIVE="$COVERAGE_DIRECTORY/coverage-image.docker.tar"
IMAGE_ID_FILE="$COVERAGE_DIRECTORY/coverage-image.id"
FOUNDATION_MANIFEST="$COVERAGE_DIRECTORY/foundation-manifest.json"
CORE_COVERAGE="$COVERAGE_DIRECTORY/core.cov"
CORE_CLOVER="$COVERAGE_DIRECTORY/core.clover.xml"
CORE_MANIFEST="$COVERAGE_DIRECTORY/core-manifest.json"
INTEGRATION_COVERAGE="$COVERAGE_DIRECTORY/integration.cov"
INTEGRATION_CLOVER="$COVERAGE_DIRECTORY/integration.clover.xml"
INTEGRATION_MANIFEST="$COVERAGE_DIRECTORY/integration-manifest.json"
CLOVER_REPORT="$COVERAGE_DIRECTORY/clover.xml"
FAILED_CLOVER_REPORT="$COVERAGE_DIRECTORY/clover.failed.xml"
IMAGE=${HODDMIMIR_BACKEND_COVERAGE_IMAGE:-hoddmimir-backend-coverage:local}
CACHE_SCOPE=${HODDMIMIR_BACKEND_COVERAGE_CACHE_SCOPE:-}
SUCCESS=0
DIAGNOSTIC_REPORT=0

remove_artifacts() {
    for artifact in "$@"; do
        rm -f "$artifact"
        if [ -e "$artifact" ] || [ -L "$artifact" ]; then
            printf 'Could not safely clear backend coverage artifact: %s\n' "$artifact" >&2
            return 2
        fi
    done
}

cleanup() {
    status=$?
    trap - 0 1 2 15
    if [ -d "$COVERAGE_DIRECTORY" ] && [ ! -L "$COVERAGE_DIRECTORY" ]; then
        if [ "$SUCCESS" -ne 1 ]; then
            case "$PROFILE" in
                foundation)
                    rm -f "$FOUNDATION_ARCHIVE" "$IMAGE_ID_FILE" "$FOUNDATION_MANIFEST"
                    ;;
                core)
                    rm -f "$CORE_COVERAGE" "$CORE_CLOVER" "$CORE_MANIFEST"
                    ;;
                mariadb)
                    rm -f "$INTEGRATION_COVERAGE" "$INTEGRATION_CLOVER" "$INTEGRATION_MANIFEST"
                    ;;
                compose)
                    rm -f "$CLOVER_REPORT"
                    if [ "$DIAGNOSTIC_REPORT" -ne 1 ]; then
                        rm -f "$FAILED_CLOVER_REPORT"
                    fi
                    ;;
                all)
                    rm -f \
                        "$FOUNDATION_ARCHIVE" "$IMAGE_ID_FILE" "$FOUNDATION_MANIFEST" \
                        "$CORE_COVERAGE" "$CORE_CLOVER" "$CORE_MANIFEST" \
                        "$INTEGRATION_COVERAGE" "$INTEGRATION_CLOVER" "$INTEGRATION_MANIFEST" \
                        "$CLOVER_REPORT"
                    if [ "$DIAGNOSTIC_REPORT" -ne 1 ]; then
                        rm -f "$FAILED_CLOVER_REPORT"
                    fi
                    ;;
            esac
        elif [ "$PROFILE" = all ]; then
            rm -f \
                "$FOUNDATION_ARCHIVE" "$IMAGE_ID_FILE" "$FOUNDATION_MANIFEST" \
                "$CORE_COVERAGE" "$CORE_CLOVER" "$CORE_MANIFEST" \
                "$INTEGRATION_COVERAGE" "$INTEGRATION_CLOVER" "$INTEGRATION_MANIFEST"
        elif [ "$PROFILE" = core ]; then
            rm -f "$CORE_COVERAGE"
        elif [ "$PROFILE" = mariadb ]; then
            rm -f "$INTEGRATION_COVERAGE"
        fi
    fi
    exit "$status"
}

trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

assert_safe_artifact() {
    artifact=$1
    label=$2
    if [ ! -f "$artifact" ] || [ -L "$artifact" ] || [ ! -s "$artifact" ]; then
        printf '%s was not exported safely: %s\n' "$label" "$artifact" >&2
        exit 2
    fi
}

prepare_directory() {
    if [ -L "$COVERAGE_DIRECTORY" ]; then
        printf 'Refusing to use a symlinked backend coverage directory: %s\n' "$COVERAGE_DIRECTORY" >&2
        exit 2
    fi
    mkdir -p "$COVERAGE_DIRECTORY"
    if [ ! -d "$COVERAGE_DIRECTORY" ] || [ -L "$COVERAGE_DIRECTORY" ]; then
        printf 'Backend coverage path is not a safe directory: %s\n' "$COVERAGE_DIRECTORY" >&2
        exit 2
    fi
}

read_image_id() {
    assert_safe_artifact "$IMAGE_ID_FILE" 'Coverage image identity'
    IMAGE_ID=$(tr -d '\r\n' < "$IMAGE_ID_FILE")
    if ! printf '%s\n' "$IMAGE_ID" | grep -Eq '^sha256:[0-9a-f]{64}$'; then
        printf 'Coverage foundation contains an invalid image identity: %s\n' "$IMAGE_ID" >&2
        exit 2
    fi
}

assert_image_identity() {
    actual_image_id=$(docker image inspect --format '{{.Id}}' "$IMAGE_ID")
    if [ "$actual_image_id" != "$IMAGE_ID" ]; then
        printf 'Coverage image identity drifted: expected %s, got %s\n' "$IMAGE_ID" "$actual_image_id" >&2
        exit 2
    fi
}

write_manifest() {
    output=$1
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --env HOME=/tmp \
        --volume "$COVERAGE_DIRECTORY:/app/coverage" \
        "$IMAGE_ID" \
        php tools/compose-owned-coverage.php manifest "$IMAGE_ID" "$output"
}

build_foundation() {
    remove_artifacts "$FOUNDATION_ARCHIVE" "$IMAGE_ID_FILE" "$FOUNDATION_MANIFEST"
    if [ -n "$CACHE_SCOPE" ]; then
        if ! printf '%s\n' "$CACHE_SCOPE" | grep -Eq '^[A-Za-z0-9_.-]{1,128}$'; then
            printf 'Coverage Buildx cache scope is invalid.\n' >&2
            exit 2
        fi
        docker buildx build \
            --load \
            --iidfile "$IMAGE_ID_FILE" \
            --target backend-coverage-runtime \
            --tag "$IMAGE" \
            --file "$REPOSITORY_ROOT/docker/php/Dockerfile" \
            --cache-from "type=gha,scope=$CACHE_SCOPE" \
            --cache-to "type=gha,mode=max,scope=$CACHE_SCOPE" \
            "$REPOSITORY_ROOT"
    else
        docker build \
            --iidfile "$IMAGE_ID_FILE" \
            --target backend-coverage-runtime \
            --tag "$IMAGE" \
            --file "$REPOSITORY_ROOT/docker/php/Dockerfile" \
            "$REPOSITORY_ROOT"
    fi
    read_image_id
    assert_image_identity
    write_manifest coverage/foundation-manifest.json
    assert_safe_artifact "$FOUNDATION_MANIFEST" 'Coverage foundation manifest'
    docker save --output "$FOUNDATION_ARCHIVE" "$IMAGE_ID"
    assert_safe_artifact "$FOUNDATION_ARCHIVE" 'Coverage image archive'
    assert_image_identity
}

load_foundation() {
    assert_safe_artifact "$FOUNDATION_ARCHIVE" 'Coverage image archive'
    assert_safe_artifact "$FOUNDATION_MANIFEST" 'Coverage foundation manifest'
    read_image_id
    docker load --input "$FOUNDATION_ARCHIVE" >/dev/null
    assert_image_identity
}

prepare_owner_manifest() {
    output=$1
    absolute=$2
    remove_artifacts "$absolute"
    write_manifest "$output"
    assert_safe_artifact "$absolute" 'Coverage owner manifest'
    if ! cmp -s "$FOUNDATION_MANIFEST" "$absolute"; then
        printf 'Coverage owner manifest does not match the immutable foundation.\n' >&2
        exit 2
    fi
    assert_image_identity
}

run_core() {
    remove_artifacts "$CORE_COVERAGE" "$CORE_CLOVER" "$CORE_MANIFEST"
    load_foundation
    prepare_owner_manifest coverage/core-manifest.json "$CORE_MANIFEST"
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --env HOME=/tmp \
        --env XDEBUG_MODE=coverage \
        --volume "$COVERAGE_DIRECTORY:/app/coverage" \
        "$IMAGE_ID" \
        php -d memory_limit=2G vendor/bin/phpunit \
            --configuration phpunit.coverage-core.xml.dist \
            --coverage-php coverage/core.cov \
            --coverage-clover coverage/core.clover.xml \
            --coverage-text
    assert_safe_artifact "$CORE_COVERAGE" 'Core PHP coverage artifact'
    assert_safe_artifact "$CORE_CLOVER" 'Core Clover report'
    assert_image_identity
}

run_mariadb() {
    remove_artifacts "$INTEGRATION_COVERAGE" "$INTEGRATION_CLOVER" "$INTEGRATION_MANIFEST"
    load_foundation
    prepare_owner_manifest coverage/integration-manifest.json "$INTEGRATION_MANIFEST"
    HODDMIMIR_BACKEND_COVERAGE_IMAGE="$IMAGE_ID" \
        "$REPOSITORY_ROOT/scripts/test-backend-integration.sh" --coverage
    assert_safe_artifact "$INTEGRATION_COVERAGE" 'MariaDB PHP coverage artifact'
    assert_safe_artifact "$INTEGRATION_CLOVER" 'MariaDB Clover report'
    assert_image_identity
}

compose_reports() {
    remove_artifacts "$CLOVER_REPORT" "$FAILED_CLOVER_REPORT"
    load_foundation
    assert_safe_artifact "$CORE_CLOVER" 'Core Clover report'
    assert_safe_artifact "$CORE_MANIFEST" 'Core coverage manifest'
    assert_safe_artifact "$INTEGRATION_CLOVER" 'MariaDB Clover report'
    assert_safe_artifact "$INTEGRATION_MANIFEST" 'MariaDB coverage manifest'
    if ! cmp -s "$FOUNDATION_MANIFEST" "$CORE_MANIFEST" \
        || ! cmp -s "$FOUNDATION_MANIFEST" "$INTEGRATION_MANIFEST"; then
        printf 'Coverage owner manifests do not match the immutable foundation.\n' >&2
        exit 2
    fi
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --env HOME=/tmp \
        --volume "$COVERAGE_DIRECTORY:/app/coverage" \
        "$IMAGE_ID" \
        php tools/compose-owned-coverage.php compose \
            coverage/core.clover.xml \
            coverage/integration.clover.xml \
            coverage/core-manifest.json \
            coverage/integration-manifest.json \
            coverage/clover.xml
    assert_safe_artifact "$CLOVER_REPORT" 'Owned Clover report'
    assert_image_identity
    if docker run --rm \
        --user "$(id -u):$(id -g)" \
        --env HOME=/tmp \
        --volume "$COVERAGE_DIRECTORY:/app/coverage:ro" \
        "$IMAGE_ID" \
        php tools/check-coverage.php coverage/clover.xml; then
        :
    else
        gate_status=$?
        mv "$CLOVER_REPORT" "$FAILED_CLOVER_REPORT"
        DIAGNOSTIC_REPORT=1
        printf 'Coverage gate failed; diagnostic report retained at %s\n' "$FAILED_CLOVER_REPORT" >&2
        exit "$gate_status"
    fi
    assert_image_identity
}

prepare_directory
case "$PROFILE" in
    foundation) build_foundation ;;
    core) run_core ;;
    mariadb) run_mariadb ;;
    compose) compose_reports ;;
    all)
        remove_artifacts \
            "$CORE_COVERAGE" "$CORE_CLOVER" "$CORE_MANIFEST" \
            "$INTEGRATION_COVERAGE" "$INTEGRATION_CLOVER" "$INTEGRATION_MANIFEST" \
            "$CLOVER_REPORT" "$FAILED_CLOVER_REPORT"
        build_foundation
        run_core
        run_mariadb
        compose_reports
        ;;
esac
SUCCESS=1
