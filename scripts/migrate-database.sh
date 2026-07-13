#!/bin/sh

set -eu

script_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_directory/.." && pwd)
base_compose="$repository_root/compose.yaml"
migration_compose="$repository_root/compose.migration.yaml"

"$repository_root/scripts/init-dev-secrets.sh"

cd "$repository_root"

docker compose --file "$base_compose" config --quiet
docker compose --file "$base_compose" --file "$migration_compose" config --quiet
docker compose --file "$base_compose" up --detach --wait mariadb
docker compose --file "$base_compose" \
    exec -T --user 0 mariadb /usr/local/bin/hoddmimir-database-user-bootstrap
docker compose \
    --file "$base_compose" \
    --file "$migration_compose" \
    run --rm --build --no-deps schema-migration
