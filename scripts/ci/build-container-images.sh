#!/bin/sh

set -eu

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
artifact_directory=${SUPPLY_CHAIN_DIRECTORY:-"$repository_root/artifacts/supply-chain"}
target_matrix="$repository_root/scripts/ci/container-targets.txt"
platform_matrix="$repository_root/scripts/ci/container-platforms.txt"
image_directory="$artifact_directory/images"

rm -rf "$artifact_directory/oci" "$image_directory" "$artifact_directory/sbom"
mkdir -p "$image_directory"

while IFS='|' read -r artifact dockerfile target; do
    case "$artifact" in
        ''|'#'*) continue ;;
    esac

    while IFS= read -r platform; do
        test -n "$platform"
        platform_slug=$(printf '%s' "$platform" | tr '/' '-')
        archive="$image_directory/$artifact-$platform_slug.docker.tar"

        if test -n "$target"; then
            docker buildx build \
                --platform "$platform" \
                --target "$target" \
                --file "$repository_root/$dockerfile" \
                --provenance=false \
                --output "type=docker,dest=$archive" \
                "$repository_root"
        else
            docker buildx build \
                --platform "$platform" \
                --file "$repository_root/$dockerfile" \
                --provenance=false \
                --output "type=docker,dest=$archive" \
                "$repository_root"
        fi

        test -s "$archive"
    done < "$platform_matrix"
done < "$target_matrix"

archive_count=$(find "$image_directory" -type f -name '*.docker.tar' | wc -l | tr -d ' ')
test "$archive_count" -eq 6
