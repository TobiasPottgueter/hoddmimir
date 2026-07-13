#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/../.." && pwd)
SUBJECT="$REPOSITORY_ROOT/scripts/run-backend-coverage.sh"
FIXTURES="$SCRIPT_DIRECTORY/fixtures/backend-coverage"
TEMPORARY_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-backend-coverage.XXXXXX")

cleanup() {
    trap - 0 1 2 15
    rm -rf "$TEMPORARY_ROOT"
}

trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_count() {
    expected=$1
    pattern=$2
    file=$3
    actual=$(grep -Ec "$pattern" "$file" || true)
    if [ "$actual" -ne "$expected" ]; then
        cat "$file" >&2
        fail "expected $expected matches for $pattern, got $actual"
    fi
}

assert_count 1 '^docker build \\' "$SUBJECT"
grep -F -- '--iidfile "$IMAGE_ID_FILE"' "$SUBJECT" >/dev/null \
    || fail 'coverage wrapper does not capture the immutable image identity'
grep -F '"$IMAGE_ID"' "$SUBJECT" >/dev/null \
    || fail 'coverage wrapper does not execute the immutable image identity'
grep -F 'tools/compose-owned-coverage.php manifest' "$SUBJECT" >/dev/null \
    || fail 'coverage wrapper does not generate source/runtime manifests'
grep -F 'tools/compose-owned-coverage.php compose' "$SUBJECT" >/dev/null \
    || fail 'coverage wrapper does not compose disjoint owned reports'
grep -F '"$REPOSITORY_ROOT/scripts/test-backend-integration.sh" --coverage' "$SUBJECT" >/dev/null \
    || fail 'coverage wrapper does not delegate MariaDB coverage to the isolated integration stack'
if grep -E 'CodeCoverage::merge|merge-coverage|render-coverage|combined\.cov|phpunit\.coverage\.xml' "$SUBJECT" >/dev/null; then
    fail 'coverage wrapper contains obsolete or unsafe report merge logic'
fi

grep -F '<directory suffix=".php">src</directory>' "$REPOSITORY_ROOT/backend/phpunit.coverage-core.xml.dist" >/dev/null \
    || fail 'core PHPUnit configuration does not include src'
grep -F '<directory suffix=".php">src/Infrastructure/Persistence/MariaDb</directory>' "$REPOSITORY_ROOT/backend/phpunit.coverage-core.xml.dist" >/dev/null \
    || fail 'core PHPUnit configuration does not exclude the MariaDB owner tree'
grep -F '<testsuite name="mariadb-unit">' "$REPOSITORY_ROOT/backend/phpunit.coverage-mariadb.xml.dist" >/dev/null \
    || fail 'MariaDB PHPUnit configuration omits repository unit tests'
grep -F '<directory>tests/Unit/Infrastructure/Persistence/MariaDb</directory>' "$REPOSITORY_ROOT/backend/phpunit.coverage-mariadb.xml.dist" >/dev/null \
    || fail 'MariaDB PHPUnit configuration omits the repository unit-test directory'
grep -F '<directory suffix=".php">src/Infrastructure/Persistence/MariaDb</directory>' "$REPOSITORY_ROOT/backend/phpunit.coverage-mariadb.xml.dist" >/dev/null \
    || fail 'MariaDB PHPUnit configuration does not restrict its source owner tree'
grep -F 'build: !reset null' "$REPOSITORY_ROOT/compose.integration-coverage.yaml" >/dev/null \
    || fail 'coverage Compose overlay can still rebuild the backend image'
grep -F 'BACKEND_COVERAGE_PHP_FILE: /app/coverage/integration.cov' "$REPOSITORY_ROOT/compose.integration-coverage.yaml" >/dev/null \
    || fail 'coverage Compose overlay does not export MariaDB PHP coverage'
grep -F 'BACKEND_COVERAGE_CLOVER_FILE: /app/coverage/integration.clover.xml' "$REPOSITORY_ROOT/compose.integration-coverage.yaml" >/dev/null \
    || fail 'coverage Compose overlay does not export MariaDB Clover coverage'
test ! -e "$REPOSITORY_ROOT/backend/phpunit.coverage.xml.dist" \
    || fail 'obsolete combined PHPUnit configuration still exists'

run_case() {
    name=$1
    build_status=$2
    core_status=$3
    integration_status=$4
    manifest_status=$5
    compose_status=$6
    gate_status=$7
    drift_on_inspect=$8
    expected_status=$9
    symlink_mode=${10:-0}
    case_root="$TEMPORARY_ROOT/$name"
    repository="$case_root/repository"
    fake_bin="$case_root/bin"
    call_log="$case_root/docker-calls.log"

    mkdir -p \
        "$repository/scripts/tests" \
        "$repository/backend/tools" \
        "$repository/docker/php" \
        "$fake_bin"
    cp "$SUBJECT" "$repository/scripts/run-backend-coverage.sh"
    cp "$FIXTURES/test-backend-integration.sh" "$repository/scripts/test-backend-integration.sh"
    cp "$FIXTURES/docker" "$fake_bin/docker"
    touch \
        "$repository/backend/tools/compose-owned-coverage.php" \
        "$repository/backend/tools/check-coverage.php" \
        "$repository/docker/php/Dockerfile" \
        "$call_log"
    chmod +x \
        "$repository/scripts/run-backend-coverage.sh" \
        "$repository/scripts/test-backend-integration.sh" \
        "$fake_bin/docker"

    case "$symlink_mode" in
        1)
            mkdir -p "$repository/backend/coverage"
            printf 'do not overwrite\n' > "$case_root/sentinel"
            ln -s "$case_root/sentinel" "$repository/backend/coverage/clover.xml"
            ;;
        2)
            mkdir -p "$repository/backend/coverage"
            printf 'do not overwrite\n' > "$case_root/sentinel"
            ln -s "$case_root/sentinel" "$repository/backend/coverage/core.cov"
            ;;
        3)
            mkdir -p "$repository/backend" "$case_root/external-coverage"
            printf 'do not remove\n' > "$case_root/external-coverage/clover.xml"
            ln -s "$case_root/external-coverage" "$repository/backend/coverage"
            ;;
        4)
            mkdir -p "$repository/backend/coverage"
            printf 'do not overwrite\n' > "$case_root/sentinel"
            ln -s "$case_root/sentinel" "$repository/backend/coverage/coverage-image.id"
            ;;
    esac

    set +e
    (
        cd "$repository"
        PATH="$fake_bin:$PATH" \
        FAKE_DOCKER_CALL_LOG="$call_log" \
        FAKE_COVERAGE_BUILD_STATUS="$build_status" \
        FAKE_CORE_COVERAGE_STATUS="$core_status" \
        FAKE_INTEGRATION_COVERAGE_STATUS="$integration_status" \
        FAKE_MANIFEST_STATUS="$manifest_status" \
        FAKE_COMPOSE_COVERAGE_STATUS="$compose_status" \
        FAKE_COVERAGE_GATE_STATUS="$gate_status" \
        FAKE_IMAGE_DRIFT_ON_INSPECT="$drift_on_inspect" \
        ./scripts/run-backend-coverage.sh
    ) >/dev/null 2>&1
    actual_status=$?
    set -e

    if [ "$actual_status" -ne "$expected_status" ]; then
        printf '%s\n' 'Docker calls:' >&2
        cat "$call_log" >&2
        fail "$name returned $actual_status, expected $expected_status"
    fi
    if [ "$symlink_mode" -eq 3 ]; then
        assert_count 0 '^build .*--target backend-coverage-runtime ' "$call_log"
    else
        assert_count 1 '^build .*--target backend-coverage-runtime ' "$call_log"
    fi

    if [ "$symlink_mode" -eq 3 ]; then
        test "$(cat "$case_root/external-coverage/clover.xml")" = 'do not remove' \
            || fail "$name touched the external coverage sentinel"
        test -L "$repository/backend/coverage" \
            || fail "$name replaced the coverage-directory symlink"
        return
    fi

    for intermediate in \
        core.cov core.clover.xml core-manifest.json \
        integration.cov integration.clover.xml integration-manifest.json \
        coverage-image.id; do
        if [ -e "$repository/backend/coverage/$intermediate" ] || [ -L "$repository/backend/coverage/$intermediate" ]; then
            fail "$name retained intermediate artifact $intermediate"
        fi
    done

    if [ "$symlink_mode" -ne 0 ]; then
        test "$(cat "$case_root/sentinel")" = 'do not overwrite' \
            || fail "$name followed a stale artifact symlink"
    fi

    if [ "$expected_status" -eq 0 ]; then
        test -s "$repository/backend/coverage/clover.xml" || fail "$name did not retain owned Clover output"
        test -r "$repository/backend/coverage/clover.xml" || fail "$name Clover output is not readable"
        test -w "$repository/backend/coverage/clover.xml" || fail "$name Clover output is not writable"
        test ! -L "$repository/backend/coverage/clover.xml" || fail "$name retained a Clover symlink"
        grep -F -- '--user ' "$call_log" >/dev/null || fail "$name did not run artifact writers as the host UID/GID"
        grep -F 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' "$call_log" >/dev/null \
            || fail "$name did not execute the immutable image identity"
    elif [ "$gate_status" -ne 0 ] \
        && [ "$build_status" -eq 0 ] \
        && [ "$core_status" -eq 0 ] \
        && [ "$integration_status" -eq 0 ] \
        && [ "$manifest_status" -eq 0 ] \
        && [ "$compose_status" -eq 0 ] \
        && [ "$drift_on_inspect" -eq 0 ]; then
        test -s "$repository/backend/coverage/clover.failed.xml" \
            || fail "$name did not retain the current failed Clover diagnostic"
        test ! -e "$repository/backend/coverage/clover.xml" \
            || fail "$name retained the passing Clover name after a gate failure"
    else
        test ! -e "$repository/backend/coverage/clover.xml" \
            || fail "$name retained stale owned Clover output after failure"
        test ! -e "$repository/backend/coverage/clover.failed.xml" \
            || fail "$name retained stale failed Clover output"
    fi
}

run_case success             0  0  0  0  0  0  0  0
run_case build-failure       17 0  0  0  0  0  0 17
run_case core-failure        0 18  0  0  0  0  0 18
run_case integration-failure 0  0 19  0  0  0  0 19
run_case manifest-failure    0  0  0 20  0  0  0 20
run_case compose-failure     0  0  0  0 21  0  0 21
run_case gate-failure        0  0  0  0  0 22  0 22
run_case image-drift         0  0  0  0  0  0  3  2
run_case clover-symlink      0  0  0  0  0  0  0  0 1
run_case core-symlink        0  0  0  0  0  0  0  0 2
run_case coverage-directory  0  0  0  0  0  0  0  2 3
run_case image-id-symlink    0  0  0  0  0  0  0  0 4

printf 'Backend coverage wrapper tests passed.\n'
