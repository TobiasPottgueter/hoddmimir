# Reproducible browser QA

Hoddmímir's critical WebApp paths run against the real PHP API and a real,
disposable MariaDB database. The suite does not mock HTTP responses and does
not expose a fixture endpoint.

Run the complete local gate from the repository root:

```sh
make e2e
```

The runner performs these steps in order:

1. creates missing ignored development secrets;
2. builds the pinned MariaDB, WebApp, QA seed and Playwright images;
3. starts an isolated MariaDB database on a `tmpfs` volume;
4. applies all migrations and invokes `hoddmimir:qa:seed` in `APP_ENV=test`;
5. starts the WebApp with its real authenticator;
6. runs desktop Chromium and mobile Chromium flows;
7. removes containers, the network and database volume even after a failure.

The WebApp process uses the dedicated `APP_ENV=e2e` and `web-e2e` image target
only inside this isolated stack. Authentication still uses the production
implementation; the E2E service configuration merely relaxes the secure-cookie
flag for the HTTP-only loopback stack and replaces the external PVE/PBS
onboarding boundary with deterministic test evidence. The production `web`
image installs no development dependencies and cannot autoload that fake.
Playwright shares the WebApp network namespace and uses its loopback origin so
browser secure-context APIs such as `crypto.randomUUID()` remain available.
None of these settings are used by the production Compose model.

## Deterministic fixture

The seed command is deliberately restricted to the Symfony `test`
environment and accepts its administrator password only from a regular,
non-symlink file directly below `/run/secrets`. It creates:

- `qa-admin` with the full administrator role;
- `qa-viewer` with read-only permissions;
- one PVE connection and cluster;
- two nodes, one QEMU VM and one LXC container;
- one backup-capable PVE storage with capacity and placement evidence;
- one disabled backup target;
- one enabled policy with global inclusion, explicit QEMU exclusion and an LXC
  guest override.
- one deliberately stale collector heartbeat and no backup-worker heartbeat,
  so outage warnings remain deterministic;
- one successful backup run with append-only events, a sanitized task log and
  one delivered recovery notification.

Fixture timestamps are generated at seed time so freshness behavior remains
deterministic. The command is not registered as an HTTP route and the E2E
Compose file is separate from production deployment.

## Covered flows

The current suite verifies:

- failed login and real local-session login;
- read-only RBAC for the viewer;
- PVE connection creation and write-only credential rotation;
- candidate evidence and candidate-to-target configuration;
- explicit Node, capacity, PBS and Executor evidence plus disabled activation
  when server-side evidence is incomplete;
- revision-conflict handling after a concurrent API update;
- policy creation and update with configured PVE failure-mail recipients;
- node, QEMU and LXC selection hierarchy plus guest overrides;
- a `never_backed_up` Shadow decision;
- manual request, queue, keyboard-confirmed cancel, run detail, events, task
  log, notification delivery state and audit evidence;
- stale and missing worker-heartbeat warnings plus versioned runtime health;
- the absence of manual scan and direct backup-start actions;
- critical navigation and horizontal-overflow behavior on a mobile viewport;
- mobile connection dialog, policy form and run-detail usability.

HTML reports and failure traces are written below
`frontend/playwright-report/` and `frontend/test-results/`. Both directories
are ignored by Git. The runner deletes old artifacts before every run.

The deterministic QA stack proves the local Phase-6 browser contract. Live PVE
7/8/9 and PBS 3/4 acceptance remains the separate environment-backed Phase-7
gate; QA fixtures do not replace it, and Phase 7 has not begun. The local
acceptance worktree's automated and manual browser evidence is recorded in the
[Phase-6 local acceptance](phase-6-local-acceptance.md).
