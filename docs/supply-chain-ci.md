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
`container-<artifact>-linux-amd64`. The worker, web, and MariaDB publication
builds read those validated scopes and maintain separate
`publish-<artifact>-linux-amd64` scopes. Cache reuse affects only build inputs;
publication still creates provenance and SBOM attestations, pushes only the
`linux/amd64` image, validates its digest, and proves anonymous access.

Manual publication is an exact three-image operation. It publishes the final
stage of `docker/mariadb/Dockerfile` beside the worker and web images; an
upstream `mariadb` reference is not interchangeable with that scanned image.
Each parallel publisher anonymously resolves the pushed OCI index, permits
exactly one application manifest (`linux/amd64`) plus only `unknown/unknown`
Buildx attestation descriptors, and hashes the exact returned index bytes
against the digest emitted by the push. It then reads the selected platform
manifest and its config blob by digest, verifies descriptor digest, size, and
media type at both boundaries, and accepts source/revision labels only from
that cryptographically bound config. Every attestation manifest is itself
digest- and size-checked and its exact Buildx reference annotation must bind it
to the selected platform manifest. An unbound or merely application-shaped
`unknown/unknown` descriptor and any additional application architecture fail
the release.

The retained `published-images.json` is a closed, versioned evidence document.
For all three images it contains both the immutable OCI registry/index digest
and the concrete `linux/amd64` platform-manifest digest. Its `deployment`
section exports the four Ansible references: collector and backup worker share
the same worker platform reference, followed by the web and project MariaDB
platform references. It also exports the three corresponding registry-index
digests so Ansible can reject an index accidentally copied into a runtime
reference. The three index digests and the three platform digests are each
mutually unique, and no platform digest may equal any image's index digest.
Deployment enforces the same closed-set rule and must use the platform
references, never a tag, an upstream MariaDB image, or a registry-index
reference.

Local `make container-images` and `make container-security` enforce the same
three-image release set. `CONTAINER_ARTIFACT` and
`CONTAINER_PLATFORM` are an internal CI selection pair; supplying only one or
selecting a nonexistent combination fails closed.

The Dockerfiles intentionally contain no architecture pin. Builds outside the
mandatory `linux/amd64` platform matrix are optional local experiments only:
they are not release candidates, are not scanned as production evidence, and
are never published by the release job.
