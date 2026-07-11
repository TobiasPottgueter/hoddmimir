#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPOSITORY_ROOT=$(CDPATH= cd -- "$SCRIPT_DIRECTORY/../.." && pwd)
SUBJECT="$REPOSITORY_ROOT/scripts/test-backend-integration.sh"
FIXTURE_DIRECTORY="$SCRIPT_DIRECTORY/fixtures/integration-cleanup"
TEMPORARY_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/hoddmimir-integration-cleanup.XXXXXX")

remove_temporary_root() {
    trap - 0 1 2 15
    rm -rf "$TEMPORARY_ROOT"
}

trap remove_temporary_root 0
trap 'exit 129' 1
trap 'exit 130' 2
trap 'exit 143' 15

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_contains() {
    haystack=$1
    needle=$2

    case "$haystack" in
        *"$needle"*)
            ;;
        *)
            fail "expected output to contain: $needle"
            ;;
    esac
}

assert_call_count() {
    call_log=$1
    pattern=$2
    expected=$3
    actual=$(grep -c -- "$pattern" "$call_log" || true)

    if [ "$actual" -ne "$expected" ]; then
        fail "expected $expected call(s) matching '$pattern', got $actual"
    fi
}

run_case() {
    name=$1
    init_status=$2
    up_status=$3
    down_status=$4
    expected_status=$5
    expected_up_calls=$6
    expected_message=${7:-}
    case_root="$TEMPORARY_ROOT/$name"
    test_repository="$case_root/repository"
    fake_bin="$case_root/bin"
    call_log="$case_root/docker-calls.log"

    mkdir -p "$test_repository/scripts" "$fake_bin"
    cp "$SUBJECT" "$test_repository/scripts/test-backend-integration.sh"
    cp "$FIXTURE_DIRECTORY/init-dev-secrets.sh" "$test_repository/scripts/init-dev-secrets.sh"
    cp "$FIXTURE_DIRECTORY/docker" "$fake_bin/docker"
    touch "$test_repository/compose.integration.yaml" "$call_log"
    chmod +x \
        "$test_repository/scripts/test-backend-integration.sh" \
        "$test_repository/scripts/init-dev-secrets.sh" \
        "$fake_bin/docker"

    set +e
    output=$(
        PATH="$fake_bin:$PATH" \
        FAKE_DOCKER_CALL_LOG="$call_log" \
        FAKE_INIT_DEV_SECRETS_STATUS="$init_status" \
        FAKE_DOCKER_UP_STATUS="$up_status" \
        FAKE_DOCKER_DOWN_STATUS="$down_status" \
        HODDMIMIR_INTEGRATION_PROJECT="cleanup-test-$name" \
        "$test_repository/scripts/test-backend-integration.sh" 2>&1
    )
    actual_status=$?
    set -e

    if [ "$actual_status" -ne "$expected_status" ]; then
        printf '%s\n' "$output" >&2
        fail "$name returned $actual_status, expected $expected_status"
    fi

    if [ -n "$expected_message" ]; then
        assert_contains "$output" "$expected_message"
    fi

    assert_call_count "$call_log" ' up --build --abort-on-container-exit ' "$expected_up_calls"
    assert_call_count "$call_log" ' down --volumes --remove-orphans' 1
}

run_case successful-cleanup 0 0 0 0 1
run_case cleanup-failure 0 0 23 23 1 \
    'Integration command succeeded, but cleanup failed with exit status 23.'
run_case primary-failure 0 42 0 42 1 \
    'Integration command failed with exit status 42; cleanup completed successfully.'
run_case combined-failure 0 42 23 42 1 \
    'Integration command failed with exit status 42; cleanup also failed with exit status 23.'
run_case pre-up-failure 41 0 0 41 0 \
    'Integration command failed with exit status 41; cleanup completed successfully.'

printf 'Integration cleanup wrapper tests passed.\n'
