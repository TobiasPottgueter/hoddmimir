#!/bin/sh

set -eu

script_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_directory/../.." && pwd)
fixture_directory="$script_directory/fixtures/migration-wrapper"
temporary_directory=$(mktemp -d)

cleanup() {
    rm -rf "$temporary_directory"
}
trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

prepare_case() {
    case_name=$1
    case_root="$temporary_directory/$case_name"
    mkdir -p "$case_root/scripts" "$case_root/bin"
    cp "$repository_root/scripts/migrate-database.sh" "$case_root/scripts/migrate-database.sh"
    cp "$fixture_directory/init-dev-secrets.sh" "$case_root/scripts/init-dev-secrets.sh"
    cp "$fixture_directory/docker" "$case_root/bin/docker"
    chmod 0755 \
        "$case_root/scripts/migrate-database.sh" \
        "$case_root/scripts/init-dev-secrets.sh" \
        "$case_root/bin/docker"
    : > "$case_root/compose.yaml"
    : > "$case_root/compose.migration.yaml"
}

run_case() {
    case_name=$1
    expected_status=$2
    fail_call=$3
    init_fail=$4
    expected_calls=$5
    prepare_case "$case_name"
    case_root="$temporary_directory/$case_name"
    docker_log="$case_root/docker.log"

    if HODDMIMIR_MIGRATION_DOCKER_LOG="$docker_log" \
        HODDMIMIR_MIGRATION_FAIL_CALL="$fail_call" \
        HODDMIMIR_MIGRATION_FAIL_CODE=42 \
        HODDMIMIR_MIGRATION_INIT_FAIL="$init_fail" \
        HODDMIMIR_MIGRATION_INIT_FAIL_CODE=41 \
        PATH="$case_root/bin:$PATH" \
        "$case_root/scripts/migrate-database.sh" >/dev/null 2>&1; then
        actual_status=0
    else
        actual_status=$?
    fi

    test "$expected_status" -eq "$actual_status"
    if [ "$expected_calls" -eq 0 ]; then
        test ! -s "$docker_log"
        return
    fi

    test -f "$docker_log"
    test "$expected_calls" -eq "$(wc -l < "$docker_log" | tr -d ' ')"
    test "$(sed -n '1p' "$docker_log")" = "compose --file $case_root/compose.yaml config --quiet"
    if [ "$expected_calls" -ge 2 ]; then
        test "$(sed -n '2p' "$docker_log")" = "compose --file $case_root/compose.yaml --file $case_root/compose.migration.yaml config --quiet"
    fi
    if [ "$expected_calls" -ge 3 ]; then
        test "$(sed -n '3p' "$docker_log")" = "compose --file $case_root/compose.yaml up --detach --wait mariadb"
    fi
    if [ "$expected_calls" -ge 4 ]; then
        test "$(sed -n '4p' "$docker_log")" = "compose --file $case_root/compose.yaml exec -T --user 0 mariadb /usr/local/bin/hoddmimir-database-user-bootstrap"
    fi
    if [ "$expected_calls" -ge 5 ]; then
        test "$(sed -n '5p' "$docker_log")" = "compose --file $case_root/compose.yaml --file $case_root/compose.migration.yaml run --rm --build --no-deps schema-migration"
        ! grep -Eq '(^| )(down|stop|rm)( |$)|--volumes|-v' "$docker_log"
    fi
}

run_case success 0 0 0 5
run_case init-failure 41 0 1 0
run_case base-config-failure 42 1 0 1
run_case overlay-config-failure 42 2 0 2
run_case database-up-failure 42 3 0 3
run_case database-bootstrap-failure 42 4 0 4
run_case migration-run-failure 42 5 0 5
