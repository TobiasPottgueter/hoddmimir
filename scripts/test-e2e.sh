#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
compose_file="$project_root/compose.e2e.yaml"

cleanup() {
    docker compose --file "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

"$project_root/scripts/init-dev-secrets.sh"
rm -rf "$project_root/frontend/playwright-report" "$project_root/frontend/test-results"
mkdir -p "$project_root/frontend/playwright-report" "$project_root/frontend/test-results"

docker compose --file "$compose_file" build mariadb-e2e qa-seed webapp-e2e playwright
docker compose --file "$compose_file" up --detach --wait mariadb-e2e
docker compose --file "$compose_file" run --rm qa-seed
docker compose --file "$compose_file" up --detach --wait webapp-e2e
docker compose --file "$compose_file" run --rm playwright
