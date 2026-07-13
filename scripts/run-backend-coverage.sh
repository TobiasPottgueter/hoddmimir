#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/.." && pwd)
COVERAGE_DIRECTORY="$REPOSITORY_ROOT/backend/coverage"
CORE_COVERAGE="$COVERAGE_DIRECTORY/core.cov"
CORE_CLOVER="$COVERAGE_DIRECTORY/core.clover.xml"
CORE_MANIFEST="$COVERAGE_DIRECTORY/core-manifest.json"
INTEGRATION_COVERAGE="$COVERAGE_DIRECTORY/integration.cov"
INTEGRATION_CLOVER="$COVERAGE_DIRECTORY/integration.clover.xml"
INTEGRATION_MANIFEST="$COVERAGE_DIRECTORY/integration-manifest.json"
CLOVER_REPORT="$COVERAGE_DIRECTORY/clover.xml"
FAILED_CLOVER_REPORT="$COVERAGE_DIRECTORY/clover.failed.xml"
IMAGE_ID_FILE="$COVERAGE_DIRECTORY/coverage-image.id"
IMAGE=${HODDMIMIR_BACKEND_COVERAGE_IMAGE:-hoddmimir-backend-coverage:local}
SUCCESS=0
DIAGNOSTIC_REPORT=0

cleanup() {
    status=$?
    trap - 0 1 2 15
    if [ -d "$COVERAGE_DIRECTORY" ] && [ ! -L "$COVERAGE_DIRECTORY" ]; then
        rm -f \
            "$CORE_COVERAGE" \
            "$CORE_CLOVER" \
            "$CORE_MANIFEST" \
            "$INTEGRATION_COVERAGE" \
            "$INTEGRATION_CLOVER" \
            "$INTEGRATION_MANIFEST" \
            "$IMAGE_ID_FILE"
        if [ "$SUCCESS" -ne 1 ]; then
            rm -f "$CLOVER_REPORT"
            if [ "$DIAGNOSTIC_REPORT" -ne 1 ]; then
                rm -f "$FAILED_CLOVER_REPORT"
            fi
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

assert_image_identity() {
    actual_image_id=$(docker image inspect --format '{{.Id}}' "$IMAGE_ID")
    if [ "$actual_image_id" != "$IMAGE_ID" ]; then
        printf 'Coverage image identity drifted: expected %s, got %s\n' "$IMAGE_ID" "$actual_image_id" >&2
        exit 2
    fi
}

if [ -L "$COVERAGE_DIRECTORY" ]; then
    printf 'Refusing to use a symlinked backend coverage directory: %s\n' "$COVERAGE_DIRECTORY" >&2
    exit 2
fi
mkdir -p "$COVERAGE_DIRECTORY"
if [ ! -d "$COVERAGE_DIRECTORY" ] || [ -L "$COVERAGE_DIRECTORY" ]; then
    printf 'Backend coverage path is not a safe directory: %s\n' "$COVERAGE_DIRECTORY" >&2
    exit 2
fi

for artifact in \
    "$CORE_COVERAGE" \
    "$CORE_CLOVER" \
    "$CORE_MANIFEST" \
    "$INTEGRATION_COVERAGE" \
    "$INTEGRATION_CLOVER" \
    "$INTEGRATION_MANIFEST" \
    "$CLOVER_REPORT" \
    "$FAILED_CLOVER_REPORT" \
    "$IMAGE_ID_FILE"; do
    rm -f "$artifact"
    if [ -e "$artifact" ] || [ -L "$artifact" ]; then
        printf 'Could not safely clear backend coverage artifact: %s\n' "$artifact" >&2
        exit 2
    fi
done

docker build \
    --iidfile "$IMAGE_ID_FILE" \
    --target backend-coverage-runtime \
    --tag "$IMAGE" \
    --file "$REPOSITORY_ROOT/docker/php/Dockerfile" \
    "$REPOSITORY_ROOT"

assert_safe_artifact "$IMAGE_ID_FILE" 'Coverage image identity'
IMAGE_ID=$(tr -d '\r\n' < "$IMAGE_ID_FILE")
if ! printf '%s\n' "$IMAGE_ID" | grep -Eq '^sha256:[0-9a-f]{64}$'; then
    printf 'Coverage build returned an invalid image identity: %s\n' "$IMAGE_ID" >&2
    exit 2
fi
assert_image_identity

docker run --rm \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp \
    --volume "$COVERAGE_DIRECTORY:/app/coverage" \
    "$IMAGE_ID" \
    php tools/compose-owned-coverage.php manifest \
        "$IMAGE_ID" coverage/core-manifest.json

assert_safe_artifact "$CORE_MANIFEST" 'Core coverage manifest'
assert_image_identity

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

docker run --rm \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp \
    --volume "$COVERAGE_DIRECTORY:/app/coverage" \
    "$IMAGE_ID" \
    php tools/compose-owned-coverage.php manifest \
        "$IMAGE_ID" coverage/integration-manifest.json

assert_safe_artifact "$INTEGRATION_MANIFEST" 'MariaDB coverage manifest'
assert_image_identity

HODDMIMIR_BACKEND_COVERAGE_IMAGE="$IMAGE_ID" \
    "$REPOSITORY_ROOT/scripts/test-backend-integration.sh" --coverage

assert_safe_artifact "$INTEGRATION_COVERAGE" 'MariaDB PHP coverage artifact'
assert_safe_artifact "$INTEGRATION_CLOVER" 'MariaDB Clover report'
assert_image_identity

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
SUCCESS=1
