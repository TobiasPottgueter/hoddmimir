# Phase-7 PVE fault proxy

Status as of 15 July 2026: the harness and its local contract tests are
available, but no live PVE fault-injection run has been accepted yet. The
authoritative progress boundary is tracked in
[`docs/phase-7-live-acceptance.md`](../../docs/phase-7-live-acceptance.md).

This directory contains a lab-only, Python-standard-library TLS proxy for the
two ambiguous write-response tests in Phase 7. It is not imported by an
application component, included in an application image, or installed by the
production Ansible roles.

The proxy accepts TLS from Hoddmímir, verifies the PVE upstream certificate and
hostname, forwards the request, and reads the complete upstream response. It
can then either terminate the client connection or, with a second explicit
activation acknowledgement, hold an allowlisted response before sending its
first client byte. A hold-enabled process is deliberately one-shot:
`--hold-count` must be exactly `1`, while the independent fault count remains
configurable. The bounded latch makes the persisted cancel-`dispatching`
worker-crash window deterministic without adding a production dependency.

The hold control directory must be root-owned mode `0700`. The proxy binds that
directory and `state.json` by inode, and validates the exact `RELEASE\n` control
file through an opened descriptor. Both files are mode `0600`; neither contains
headers, bodies, credentials, node names, task identifiers or UPIDs. The proxy
never deletes externally replaceable control entries. Sanitized state and the
release file therefore remain as evidence until the stopped process's dedicated
control directory is archived or removed by the operator.
The sanitized metrics add the states `upstream_complete`, `hold_entered`,
`client_gone`, `released` and `fault`. With no hold route configured, behavior
is unchanged.

Run the local contract suite with:

```sh
python3 -m unittest discover -s lab/fault-proxy/tests -p 'test_*.py' -v
```

From the repository root, `make fault-harness-test` also compiles the proxy and
runs this suite. The GitHub CI fault-harness job executes the same target and
blocks release image publication when it fails.

The harness itself needs only Python 3.11 or newer. The test suite additionally
uses the local `openssl` command to create short-lived certificates in a
temporary directory.

For ADR 0005's absent-task case, `--fault-timing before-upstream` requires
`--activation-ack DROP_VZDUMP_BEFORE_UPSTREAM` and permits only `post-vzdump`.
It consumes the bounded fault count after receiving the request body and closes
the client connection without contacting PVE. Reads and subsequent attempts
are forwarded normally with verified upstream TLS. Response holds cannot be
combined with this mode. A dropped attempt has `received=1`,
`faultsInjected=1`, `upstreamResponses=0`, and `responsesForwarded=0`.
The default `after-upstream` mode and its acknowledgement remain unchanged.

Operational use is documented in
[`docs/phase-7-fault-injection-runbook.md`](../../docs/phase-7-fault-injection-runbook.md).
