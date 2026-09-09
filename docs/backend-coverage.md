# Backend coverage gate

The backend coverage gate keeps one ownership model and one set of thresholds while allowing CI to execute its two disjoint test owners in parallel.

## Ownership

- `core` owns every handwritten PHP source file except `src/Infrastructure/Persistence/MariaDb/`.
- `mariadb` owns exactly `src/Infrastructure/Persistence/MariaDb/` and runs against the real isolated MariaDB integration stack.
- `compose` accepts the reports only when both owner sets are disjoint and complete. It then enforces the existing line and branch thresholds on the combined Clover report.

`backend/tools/compose-owned-coverage.php` remains authoritative for source ownership, runtime signatures, manifests, source hashes and report composition. Splitting the core suite further would create overlapping source ownership and is intentionally not supported.

## CI phases

The `Backend validation` job and coverage foundation job start independently. The foundation job builds the coverage runtime once, captures its exact `sha256:` image identity, creates a canonical source/runtime manifest and exports the image as an uncompressed workflow artifact. A stable Buildx cache scope accelerates this build but does not replace any identity check.

The Core and MariaDB jobs independently load that exact image archive. Each regenerates the canonical manifest inside the loaded image and must match the foundation byte for byte before tests run. Their small Clover and manifest artifacts feed the final job named `Backend`, which preserves the stable required-check surface for downstream jobs and branch protection.

Every artifact must be a non-empty regular file and may not be a symlink. Invalid image IDs, load/save failures, image drift, missing artifacts, manifest drift, source drift, overlapping ownership and coverage threshold failures all fail closed. On a threshold failure the generated report is retained as `clover.failed.xml` locally for diagnosis.

## Local commands

`make backend-coverage` keeps the convenient sequential local path and performs foundation, Core, MariaDB and composition in one invocation. CI uses the explicit phases:

```sh
./scripts/run-backend-coverage.sh foundation
./scripts/run-backend-coverage.sh core
./scripts/run-backend-coverage.sh mariadb
./scripts/run-backend-coverage.sh compose
```

The contract suite does not require Docker:

```sh
make backend-coverage-wrapper-test
```
