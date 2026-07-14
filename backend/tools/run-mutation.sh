#!/bin/sh

set -eu

profile=${1:-all}
threads=${INFECTION_THREADS:-max}
coverage_directory=var/mutation/coverage
coverage_fingerprint_file="$coverage_directory/input.sha256"

case "$profile" in
    config|coverage|shard|aggregate|critical|global|all) ;;
    *)
        echo "Usage: $0 {config|coverage|shard|aggregate|critical|global|all}" >&2
        exit 2
        ;;
esac

if [ -d phpunit.xml ]; then
    echo "phpunit.xml must be a file, but a directory shadows phpunit.xml.dist." >&2
    exit 1
fi

check_configuration() {
    configuration=$1
    php vendor/bin/infection config:list-sources --configuration="$configuration" >/dev/null
}

run_profile() {
    configuration=$1
    report_directory=$2

    rm -rf "$report_directory"
    mkdir -p "$report_directory"
    php -d memory_limit=2G vendor/bin/infection \
        --configuration="$configuration" \
        --coverage="$coverage_directory" \
        --threads="$threads" \
        --skip-initial-tests \
        --only-covering-test-cases \
        --no-progress
}

check_configuration infection-critical.json5.dist
check_configuration infection.json5.dist

if [ "$profile" = config ]; then
    echo "Infection configuration and source selection passed."
    exit 0
fi

coverage_fingerprint=$(
    {
        find src tests config migrations -type f -print0
        printf '%s\0' composer.lock phpunit.xml.dist infection-critical.json5.dist infection.json5.dist tools/run-mutation.sh tools/mutation-shards.php
    } | sort -z | xargs -0 sha256sum | sha256sum | cut -d ' ' -f 1
)

stored_coverage_fingerprint=''
if [ -f "$coverage_fingerprint_file" ]; then
    stored_coverage_fingerprint=$(cat "$coverage_fingerprint_file")
fi

if [ "$profile" = shard ]; then
    if [ ! -s "$coverage_directory/junit.xml" ] \
        || [ ! -d "$coverage_directory/coverage-xml" ] \
        || [ "$stored_coverage_fingerprint" != "$coverage_fingerprint" ]; then
        echo 'The mutation coverage foundation is missing or stale; shard jobs must never regenerate it.' >&2
        exit 1
    fi
elif [ "$profile" != aggregate ] && [ "${REUSE_MUTATION_COVERAGE:-0}" = 1 ] \
    && [ -s "$coverage_directory/junit.xml" ] \
    && [ -d "$coverage_directory/coverage-xml" ] \
    && [ "$stored_coverage_fingerprint" = "$coverage_fingerprint" ]; then
    echo "Reusing existing PHPUnit mutation coverage."
elif [ "$profile" != aggregate ]; then
    if [ "${REUSE_MUTATION_COVERAGE:-0}" = 1 ]; then
        echo "Existing mutation coverage is missing or stale; regenerating it."
    fi
    rm -rf "$coverage_directory"
    mkdir -p "$coverage_directory/coverage-xml"
    XDEBUG_MODE=coverage php -d memory_limit=1G vendor/bin/phpunit \
        --configuration phpunit.xml.dist \
        --coverage-xml "$coverage_directory/coverage-xml" \
        --log-junit "$coverage_directory/junit.xml"
    printf '%s\n' "$coverage_fingerprint" > "$coverage_fingerprint_file"
fi

if [ "$profile" = coverage ]; then
    rm -rf var/mutation/plan
    php tools/mutation-shards.php plan var/mutation/plan
    echo "Mutation coverage foundation and shard plan generated."
    exit 0
fi

if [ "$profile" = shard ]; then
    shard_name=${MUTATION_SHARD_NAME:-}
    case "$shard_name" in
        critical-[012]) configuration=infection-critical.json5.dist ;;
        global-rest-[012345678]) configuration=infection.json5.dist ;;
        *) echo 'MUTATION_SHARD_NAME is invalid.' >&2; exit 2 ;;
    esac
    php tools/mutation-shards.php verify-plan var/mutation/plan
    manifest="var/mutation/plan/$shard_name.txt"
    report_directory="var/mutation/shards/$shard_name"
    rm -rf "$report_directory"
    mkdir -p "$report_directory"
    cp "$manifest" "$report_directory/manifest.txt"
    set --
    while IFS= read -r source_path; do
        test -n "$source_path"
        set -- "$@" "$source_path"
    done < "$manifest"
    test "$#" -gt 0
    php -d memory_limit=2G vendor/bin/infection \
        --configuration="$configuration" \
        --coverage="$coverage_directory" \
        --threads="$threads" \
        --skip-initial-tests \
        --only-covering-test-cases \
        --min-msi=0 \
        --min-covered-msi=0 \
        --logger-summary-json="$report_directory/summary.json" \
        --logger-text="$report_directory/escaped-mutants.log" \
        --no-progress \
        -- "$@"
    test -s "$report_directory/summary.json"
    exit 0
fi

if [ "$profile" = aggregate ]; then
    php tools/mutation-shards.php aggregate \
        var/mutation/plan var/mutation/shards var/mutation/aggregate.json
    exit 0
fi

if [ "$profile" = critical ] || [ "$profile" = all ]; then
    run_profile infection-critical.json5.dist var/mutation/critical
fi

if [ "$profile" = global ] || [ "$profile" = all ]; then
    run_profile infection.json5.dist var/mutation/global
fi
