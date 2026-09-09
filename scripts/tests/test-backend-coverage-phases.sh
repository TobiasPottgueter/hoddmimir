#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/../.." && pwd)
SUBJECT="$REPOSITORY_ROOT/scripts/run-backend-coverage.sh"
FIXTURES="$SCRIPT_DIRECTORY/fixtures/backend-coverage"
TEMPORARY_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-backend-coverage-phases.XXXXXX")
IMAGE_ID='sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'

trap 'rm -rf "$TEMPORARY_ROOT"' 0

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

new_repository() {
    name=$1
    repository="$TEMPORARY_ROOT/$name/repository"
    fake_bin="$TEMPORARY_ROOT/$name/bin"
    call_log="$TEMPORARY_ROOT/$name/docker-calls.log"
    mkdir -p "$repository/scripts/tests" "$repository/backend/tools" "$repository/docker/php" "$fake_bin"
    cp "$SUBJECT" "$repository/scripts/run-backend-coverage.sh"
    cp "$FIXTURES/test-backend-integration.sh" "$repository/scripts/test-backend-integration.sh"
    cp "$FIXTURES/docker" "$fake_bin/docker"
    touch "$repository/backend/tools/compose-owned-coverage.php" \
        "$repository/backend/tools/check-coverage.php" \
        "$repository/docker/php/Dockerfile" "$call_log"
    chmod +x "$repository/scripts/run-backend-coverage.sh" \
        "$repository/scripts/test-backend-integration.sh" "$fake_bin/docker"
}

run_phase() {
    phase=$1
    expected=$2
    shift 2
    set +e
    (
        cd "$repository"
        env PATH="$fake_bin:$PATH" \
            FAKE_DOCKER_CALL_LOG="$call_log" \
            FAKE_COVERAGE_BUILD_STATUS=0 \
            FAKE_CORE_COVERAGE_STATUS=0 \
            FAKE_INTEGRATION_COVERAGE_STATUS=0 \
            FAKE_MANIFEST_STATUS=0 \
            FAKE_COMPOSE_COVERAGE_STATUS=0 \
            FAKE_COVERAGE_GATE_STATUS=0 \
            FAKE_IMAGE_DRIFT_ON_INSPECT=0 \
            "$@" ./scripts/run-backend-coverage.sh "$phase"
    ) >/dev/null 2>&1
    actual=$?
    set -e
    [ "$actual" -eq "$expected" ] || fail "$phase returned $actual, expected $expected"
}

new_repository success
run_phase foundation 0
for file in coverage-image.docker.tar coverage-image.id foundation-manifest.json; do
    test -s "$repository/backend/coverage/$file" || fail "foundation omitted $file"
    test ! -L "$repository/backend/coverage/$file" || fail "foundation exported symlink $file"
done
test "$(cat "$repository/backend/coverage/coverage-image.id")" = "$IMAGE_ID" \
    || fail 'foundation exported the wrong image identity'

run_phase core 0
test -s "$repository/backend/coverage/core.clover.xml" || fail 'core phase omitted Clover evidence'
test -s "$repository/backend/coverage/core-manifest.json" || fail 'core phase omitted manifest evidence'
test ! -e "$repository/backend/coverage/core.cov" || fail 'core phase retained bulky PHP coverage'

run_phase mariadb 0
test -s "$repository/backend/coverage/integration.clover.xml" || fail 'MariaDB phase omitted Clover evidence'
test -s "$repository/backend/coverage/integration-manifest.json" || fail 'MariaDB phase omitted manifest evidence'
test ! -e "$repository/backend/coverage/integration.cov" || fail 'MariaDB phase retained bulky PHP coverage'

run_phase compose 0
test -s "$repository/backend/coverage/clover.xml" || fail 'compose phase omitted final Clover report'
test "$(grep -Ec '^build .*--target backend-coverage-runtime ' "$call_log")" -eq 1 \
    || fail 'consumer phases rebuilt the immutable coverage image'
test "$(grep -Ec '^load --input ' "$call_log")" -eq 3 \
    || fail 'consumer phases did not load the exact foundation archive'

new_repository missing-foundation
run_phase core 2
test ! -s "$call_log" || fail 'missing foundation invoked Docker'

new_repository invalid-identity
mkdir -p "$repository/backend/coverage"
printf 'archive\n' > "$repository/backend/coverage/coverage-image.docker.tar"
printf 'not-an-image-id\n' > "$repository/backend/coverage/coverage-image.id"
printf '{}\n' > "$repository/backend/coverage/foundation-manifest.json"
run_phase core 2
test ! -s "$call_log" || fail 'invalid image identity invoked Docker'

new_repository save-failure
run_phase foundation 23 FAKE_COVERAGE_SAVE_STATUS=23
test ! -e "$repository/backend/coverage/coverage-image.docker.tar" \
    || fail 'failed foundation retained an image archive'
test ! -e "$repository/backend/coverage/coverage-image.id" \
    || fail 'failed foundation retained an image identity'

new_repository load-failure
run_phase foundation 0
run_phase core 24 FAKE_COVERAGE_LOAD_STATUS=24
test ! -e "$repository/backend/coverage/core.clover.xml" \
    || fail 'failed core load retained Clover evidence'

new_repository manifest-mismatch
run_phase foundation 0
run_phase core 2 FAKE_MANIFEST_MISMATCH=1
test ! -e "$repository/backend/coverage/core-manifest.json" \
    || fail 'manifest mismatch retained invalid owner evidence'

new_repository compose-manifest-mismatch
run_phase foundation 0
run_phase core 0
run_phase mariadb 0
printf '{"tampered":true}\n' > "$repository/backend/coverage/core-manifest.json"
run_phase compose 2
test ! -e "$repository/backend/coverage/clover.xml" \
    || fail 'compose accepted owner evidence that diverged from the foundation'

printf 'Backend coverage phase tests passed.\n'
