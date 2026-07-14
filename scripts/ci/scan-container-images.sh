#!/bin/sh

set -eu

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
artifact_directory=${SUPPLY_CHAIN_DIRECTORY:-"$repository_root/artifacts/supply-chain"}
trivy_image=${TRIVY_IMAGE:?TRIVY_IMAGE must be a version- and digest-pinned image reference}
target_matrix="$repository_root/scripts/ci/container-targets.txt"
platform_matrix="$repository_root/scripts/ci/container-platforms.txt"
image_directory="$artifact_directory/images"
selected_artifact=${CONTAINER_ARTIFACT:-}
selected_platform=${CONTAINER_PLATFORM:-}

if { test -n "$selected_artifact" && test -z "$selected_platform"; } \
    || { test -z "$selected_artifact" && test -n "$selected_platform"; }; then
    echo 'CONTAINER_ARTIFACT and CONTAINER_PLATFORM must be supplied together.' >&2
    exit 2
fi

case "$trivy_image" in
    *:*@sha256:*) ;;
    *)
        echo 'TRIVY_IMAGE must include both an immutable version and sha256 digest.' >&2
        exit 2
        ;;
esac

test -d "$image_directory"
rm -rf "$artifact_directory/sbom"
mkdir -p "$artifact_directory/sbom" "$artifact_directory/trivy-cache"

run_trivy()
{
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --volume "$artifact_directory:/artifacts" \
        "$trivy_image" "$@"
}

scanned=0
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
        archive_name="$artifact-$platform_slug.docker.tar"
        sbom_name="$artifact-$platform_slug.cdx.json"
        test -s "$image_directory/$archive_name"

        run_trivy image \
            --cache-dir /artifacts/trivy-cache \
            --input "/artifacts/images/$archive_name" \
            --scanners vuln \
            --format cyclonedx \
            --output "/artifacts/sbom/$sbom_name"
        test -s "$artifact_directory/sbom/$sbom_name"

        run_trivy image \
            --cache-dir /artifacts/trivy-cache \
            --input "/artifacts/images/$archive_name" \
            --scanners vuln \
            --severity HIGH,CRITICAL \
            --exit-code 1 \
            --no-progress
        scanned=$((scanned + 1))
    done < "$platform_matrix"
done < "$target_matrix"

expected_count=6
if test -n "$selected_artifact"; then
    expected_count=1
fi
test "$scanned" -eq "$expected_count"
sbom_count=$(find "$artifact_directory/sbom" -type f -name '*.cdx.json' | wc -l | tr -d ' ')
test "$sbom_count" -eq "$expected_count"
