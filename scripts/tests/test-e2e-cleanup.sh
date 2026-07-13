#!/bin/sh

set -eu

script_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_directory/../.." && pwd)
subject="$repository_root/scripts/test-e2e.sh"
fixture_directory="$script_directory/fixtures/e2e-cleanup"
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-e2e-cleanup.XXXXXX")

cleanup() {
    trap - 0 1 2 15
    rm -rf "$temporary_root"
}

trap cleanup 0
trap 'exit 129' 1
trap 'exit 130' 2
trap 'exit 143' 15

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_call_count() {
    call_log=$1
    pattern=$2
    expected=$3
    actual=$(grep -c -- "$pattern" "$call_log" || true)

    [ "$actual" -eq "$expected" ] \
        || fail "expected $expected call(s) matching '$pattern', got $actual"
}

assert_contains() {
    haystack=$1
    needle=$2

    case "$haystack" in
        *"$needle"*) ;;
        *) fail "expected output to contain: $needle" ;;
    esac
}

run_case() {
    name=$1
    webapp_status=$2
    playwright_status=$3
    down_status=$4
    expected_status=$5
    diagnostic_ps_status=${6:-0}
    diagnostic_logs_status=${7:-0}
    case_root="$temporary_root/$name"
    test_repository="$case_root/repository"
    fake_bin="$case_root/bin"
    call_log="$case_root/docker-calls.log"

    mkdir -p "$test_repository/scripts" "$test_repository/frontend" "$fake_bin"
    cp "$subject" "$test_repository/scripts/test-e2e.sh"
    cp "$fixture_directory/init-dev-secrets.sh" "$test_repository/scripts/init-dev-secrets.sh"
    cp "$fixture_directory/docker" "$fake_bin/docker"
    touch "$test_repository/compose.e2e.yaml" "$call_log"
    chmod +x \
        "$test_repository/scripts/test-e2e.sh" \
        "$test_repository/scripts/init-dev-secrets.sh" \
        "$fake_bin/docker"

    set +e
    output=$(
        PATH="$fake_bin:$PATH" \
        FAKE_DOCKER_CALL_LOG="$call_log" \
        FAKE_DOCKER_WEBAPP_UP_STATUS="$webapp_status" \
        FAKE_DOCKER_PLAYWRIGHT_STATUS="$playwright_status" \
        FAKE_DOCKER_DOWN_STATUS="$down_status" \
        FAKE_DOCKER_PS_STATUS="$diagnostic_ps_status" \
        FAKE_DOCKER_LOGS_STATUS="$diagnostic_logs_status" \
        "$test_repository/scripts/test-e2e.sh" 2>&1
    )
    actual_status=$?
    set -e

    [ "$actual_status" -eq "$expected_status" ] \
        || fail "$name returned $actual_status, expected $expected_status: $output"

    assert_call_count "$call_log" ' down --volumes --remove-orphans$' 1

    expected_diagnostics=0
    if [ "$webapp_status" -ne 0 ] || [ "$playwright_status" -ne 0 ]; then
        expected_diagnostics=1
    fi
    assert_call_count "$call_log" ' ps --all$' "$expected_diagnostics"
    assert_call_count "$call_log" ' logs --no-color --timestamps mariadb-e2e webapp-e2e$' "$expected_diagnostics"

    if [ "$expected_diagnostics" -eq 1 ]; then
        logs_line=$(grep -n -- ' logs --no-color --timestamps mariadb-e2e webapp-e2e$' "$call_log" | cut -d: -f1)
        cleanup_line=$(grep -n -- ' down --volumes --remove-orphans$' "$call_log" | cut -d: -f1)
        [ "$logs_line" -lt "$cleanup_line" ] || fail 'expected E2E logs before cleanup'
        assert_contains "$output" 'hoddmimir secret staging failed: APP_SECRET_FILE cannot be measured'
    fi

    if [ "$diagnostic_ps_status" -ne 0 ]; then
        assert_contains "$output" 'Could not capture E2E Compose status.'
    fi
    if [ "$diagnostic_logs_status" -ne 0 ]; then
        assert_contains "$output" 'Could not capture E2E Compose logs.'
    fi
}

run_case success 0 0 0 0
run_case webapp-start-failure 70 0 0 70
run_case playwright-failure 0 42 0 42
run_case primary-and-cleanup-failure 70 0 23 70
run_case diagnostic-failure 70 0 0 70 51 52
run_case cleanup-failure 0 0 23 23

printf 'E2E cleanup wrapper tests passed.\n'
