#!/bin/sh

set -eu

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
artifact_directory=${SUPPLY_CHAIN_DIRECTORY:-"$repository_root/artifacts/supply-chain"}
target_matrix="$repository_root/scripts/ci/container-targets.txt"
platform_matrix="$repository_root/scripts/ci/container-platforms.txt"
image_directory="$artifact_directory/images"
selected_artifact=${CONTAINER_ARTIFACT:-}
selected_platform=${CONTAINER_PLATFORM:-}
cache_scope_prefix=${BUILDX_CACHE_SCOPE_PREFIX:-}
release_platform=linux/amd64

if { test -n "$selected_artifact" && test -z "$selected_platform"; } \
    || { test -z "$selected_artifact" && test -n "$selected_platform"; }; then
    echo 'CONTAINER_ARTIFACT and CONTAINER_PLATFORM must be supplied together.' >&2
    exit 2
fi

if test "$(cat "$platform_matrix")" != "$release_platform"; then
    echo "The production platform matrix must contain only $release_platform." >&2
    exit 2
fi
if test -n "$selected_platform" && test "$selected_platform" != "$release_platform"; then
    echo "CONTAINER_PLATFORM must be $release_platform for production validation." >&2
    exit 2
fi

rm -rf "$artifact_directory/oci" "$image_directory" "$artifact_directory/sbom"
mkdir -p "$image_directory"

built=0

while IFS='|' read -r artifact dockerfile target; do
    case "$artifact" in
        ''|'#'*) continue ;;
    esac
    if test -n "$selected_artifact" && test "$artifact" != "$selected_artifact"; then
        continue
    fi

    while IFS= read -r platform; do
        test -n "$platform"
        if test -n "$selected_platform" && test "$platform" != "$selected_platform"; then
            continue
        fi
        platform_slug=$(printf '%s' "$platform" | tr '/' '-')
        archive="$image_directory/$artifact-$platform_slug.docker.tar"

        set -- docker buildx build \
            --platform "$platform" \
            --file "$repository_root/$dockerfile" \
            --provenance=false \
            --output "type=docker,dest=$archive"
        if test -n "$target"; then
            set -- "$@" --target "$target"
        fi
        if test -n "$cache_scope_prefix"; then
            cache_scope="$cache_scope_prefix-$artifact-$platform_slug"
            set -- "$@" \
                --cache-from "type=gha,scope=$cache_scope" \
                --cache-to "type=gha,mode=max,scope=$cache_scope"
        fi
        set -- "$@" "$repository_root"
        "$@"

        test -s "$archive"
        built=$((built + 1))
    done < "$platform_matrix"
done < "$target_matrix"

archive_count=$(find "$image_directory" -type f -name '*.docker.tar' | wc -l | tr -d ' ')
expected_count=3
if test -n "$selected_artifact"; then
    expected_count=1
fi
test "$built" -eq "$expected_count"
test "$archive_count" -eq "$expected_count"
