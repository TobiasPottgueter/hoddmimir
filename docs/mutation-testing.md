# Mutation testing

Hoddmímir pins `infection/infection` to `0.34.0`. Packagist declares PHP
`^8.3`, which includes the project's PHP 8.5 runtime. The dependency is locked
through Composer and is also verified while the mutation Docker target is
built.

Two independent Mutation Score Indicator (MSI) gates implement the thresholds
from the rewrite plan:

| Gate | Source | Minimum MSI |
| --- | --- | ---: |
| Critical | complete `Domain`, collector scheduling, inventory orchestration and mapping, monitoring state, capability decisions, PBS-content state, and scheduler/shadow orchestration | 90% |
| Global | all handwritten application source below `backend/src` | 80% |

The critical source list is explicit in `backend/infection-critical.json5.dist`
so new scheduler or state-machine modules must be added intentionally. The
global configuration is `backend/infection.json5.dist`.

The critical set explicitly covers the complete `Domain` tree, including the
Phase-4 scheduler and Phase-5 backup state machines. Application-level
scheduler/shadow orchestration is included through `src/Application/Scheduler`;
collector `Connection`, `Pve`, `Pbs`, `Capability`, and `PbsContent` paths
remain listed explicitly. Future state-machine or scheduler application
directories must still be added intentionally.

## Allowlist

The only current mutation exclusion is `src/Kernel.php`, the Symfony bootstrap
class. There is no generated PHP below `backend/src` today. If generated or
additional bootstrap code is introduced later, its exact path may be added to
the documented allowlist; domain, application, infrastructure, presentation,
and adapter code must not be excluded. Database migrations remain covered by
the real-MariaDB schema and privilege gates and are outside the runtime source
tree mutated here.

## Running locally

All commands use the pinned PHP 8.5 Alpine test runtime and contact no Proxmox,
MariaDB, deployment host, or other external system:

```sh
make mutation-config
make mutation-critical
make mutation-global
make mutation
```

`make mutation` is the normal full local gate. It generates PHPUnit XML
coverage and JUnit output once, then supplies that same coverage directory to
both Infection runs. Because that mandatory PHPUnit baseline has already
passed, Infection skips its otherwise redundant second initial-suite run and
starts directly with mutants. Each mutant runs only the test cases identified
as covering its source line by the fresh XML/JUnit data; this keeps loop and
HTTP-heavy tests within the per-mutant timeout without weakening selection.
Set `INFECTION_THREADS=4` when a fixed concurrency is more appropriate than
automatic CPU detection.

The per-mutant timeout is 60 seconds. Timeouts still count as escaped and the
allowed timeout count remains zero; the larger budget prevents broad but valid
covering-test selections from being skipped merely because they exceed
Infection's ten-second default.

For an immediate rerun after changing only mutation configuration,
`REUSE_MUTATION_COVERAGE=1 make mutation-critical` can reuse an existing
coverage directory. The runner fingerprints production source, test code and
fixtures, application configuration, migrations, the PHPUnit configuration,
and the dependency lock; a mismatch forces fresh coverage even when reuse was
requested. The default always regenerates coverage.

Reports are written below `backend/var/mutation/` and are ignored by Git. Each
profile writes a human-readable escaped-mutant report, a summary, a JSON
summary, and a per-mutator breakdown. A threshold failure must be handled by
adding a test that observes the missing behavior or, when a mutant is provably
equivalent, by documenting the evidence during review. Broad source excludes
and business-code ignore annotations are not an acceptable fix.

## CI split

Mutation testing is deliberately split so ordinary pushes do not pay for two
full mutation campaigns:

- pull requests run the critical 90% MSI gate; branch protection can require
  `Critical Mutation Gate`;
- the complete critical plus global campaign runs every night at 02:17 UTC and
  on manual workflow dispatch;
- direct pushes keep the existing fast backend, integration, frontend, and
  container gates; maintainers run `make mutation` before merging material
  backend changes when no pull request supplies the required gate.

The full gate is still release-blocking even though it is scheduled rather than
attached to every push. A red nightly run must be fixed before a release.

The complete critical and global result for the locally accepted Phase-6
worktree is recorded in the
[Phase-6 local acceptance](phase-6-local-acceptance.md).

## Design sources

- [Infection installation and PHP compatibility](https://infection.github.io/guide/installation.html)
- [Infection configuration](https://infection.github.io/guide/usage.html)
- [Reusing PHPUnit XML and JUnit coverage](https://infection.github.io/guide/command-line-options.html#coverage)
- [MSI thresholds in CI](https://infection.github.io/guide/using-with-ci.html)
- [Pinned Composer package metadata](https://packagist.org/packages/infection/infection#0.34.0)
