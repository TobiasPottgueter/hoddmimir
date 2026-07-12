# Sanitized PBS 3/4 contract fixtures

These files are constructed, secret-free contract examples for the bounded
Hoddmímir PBS read slice. They are not production captures and contain no real
hostnames, paths, token identifiers, token secrets, certificates, fingerprints,
instance identities, or datastore data.

The pinned contract sources are:

- PBS 3 API viewer documentation 3.4.4, canonical viewer hash prefix
  `2ba388`; PBS source baseline 3.4.0 commit prefix `36ef1b`;
- PBS 4 API viewer documentation 4.2.2, canonical viewer hash prefix
  `c62063`; PBS source baseline 4.2.0 commit prefix `035c449`;
- server identity introduction commit prefix `897df9`, first included in PBS
  4.2.

The source versions, hash prefixes and reviewed source files for the first
inventory read are recorded in `docs/pbs-first-read-contract.md`. The tasks and
jobs contract records the complete API Viewer hashes used for that separate
slice. Additive `future-*` properties verify that readers remain tolerant
without enabling capabilities from unknown fields. All digest and instance-ID
values are synthetic. No unrecorded extraction procedure or full-hash claim is
made for the first-read fixtures.

The namespace/snapshot contract is recorded in
`docs/pbs-content-inventory-contract.md`. The `3` and `4` directories include
root and nested namespace/snapshot responses. Groups are deliberately derived
from snapshot rows, so no synthetic `/groups` fixture is maintained.

The GET-only tasks/jobs contract and its complete source pins are recorded in
`docs/pbs-tasks-jobs-read-contract.md`. Its fixtures are split into `3`,
`4.0`, `4.1`, and `4.2` directories and cover the three fixed job lists plus
bounded running/history task pages. Normal tests consume only these local
fixtures and never contact a PBS installation.
