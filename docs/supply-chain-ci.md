# Supply-chain CI execution

The container release gates keep the exact platform set defined by
`scripts/ci/container-targets.txt` and `scripts/ci/container-platforms.txt`:
worker, web, and MariaDB for `linux/amd64` only.

GitHub Actions executes those three combinations as independent matrix jobs.
No QEMU or binfmt emulation is installed in validation or publication.
Every job builds one Docker archive, generates one CycloneDX SBOM from that
exact archive, and runs the digest-pinned Trivy scanner with `HIGH,CRITICAL`
and exit code 1. The final `Containers and Compose` job remains the stable gate
name. It requires the Compose/smoke validation and every matrix job, downloads
the three SBOM artifacts, and rejects missing, additional, or renamed files.

Validation Buildx caches use stable scopes of the form
`container-<artifact>-linux-amd64`. The worker and web publication builds read
those validated scopes and maintain separate
`publish-<artifact>-linux-amd64` scopes. Cache reuse affects only build inputs;
publication still creates provenance and SBOM attestations, pushes only the
`linux/amd64` image, validates its digest, and proves anonymous access.

Local `make container-images` and `make container-security` enforce the same
three-image release set. `CONTAINER_ARTIFACT` and
`CONTAINER_PLATFORM` are an internal CI selection pair; supplying only one or
selecting a nonexistent combination fails closed.

The Dockerfiles intentionally contain no architecture pin. Builds outside the
mandatory `linux/amd64` platform matrix are optional local experiments only:
they are not release candidates, are not scanned as production evidence, and
are never published by the release job.
