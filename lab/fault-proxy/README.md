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
hostname, forwards the request, reads the complete upstream response, and can
then terminate the client connection before sending any response bytes. Its
only persistent output is an atomic mode-`0600` JSON file with sanitized route
and stage counters.

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

Operational use is documented in
[`docs/phase-7-fault-injection-runbook.md`](../../docs/phase-7-fault-injection-runbook.md).
