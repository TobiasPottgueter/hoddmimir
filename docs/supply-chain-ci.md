# Supply-chain CI execution

The container release gates keep the exact platform set defined by
`scripts/ci/container-targets.txt` and `scripts/ci/container-platforms.txt`:
worker, web, and MariaDB for both `linux/amd64` and `linux/arm64`.

GitHub Actions executes those six combinations as independent matrix jobs.
QEMU is installed only for the three `linux/arm64` jobs; native
`linux/amd64` jobs avoid that setup entirely.
Every job builds one Docker archive, generates one CycloneDX SBOM from that
exact archive, and runs the digest-pinned Trivy scanner with `HIGH,CRITICAL`
and exit code 1. The final `Containers and Compose` job remains the stable gate
name. It requires the Compose/smoke validation and every matrix job, downloads
the six SBOM artifacts, and rejects missing, additional, or renamed files.

Validation Buildx caches use stable scopes of the form
`container-<artifact>-linux-<architecture>`. The worker and web publication
builds read both validated platform scopes and maintain separate
`publish-<artifact>-multiarch` scopes. Cache reuse affects only build inputs;
publication still creates provenance and SBOM attestations, pushes both
platform manifests, validates their digests, and proves anonymous access.

Local `make container-multiarch` and `make container-security` retain their
original complete six-image behavior. `CONTAINER_ARTIFACT` and
`CONTAINER_PLATFORM` are an internal CI selection pair; supplying only one or
selecting a nonexistent combination fails closed.
