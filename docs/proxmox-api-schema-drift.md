# Official Proxmox API schema drift

Hoddmímir checks its selected API surface every night without contacting a PVE
or PBS installation. The workflow reads only the official, public Proxmox API
viewer data:

- [PVE 7 API Viewer](https://pve.proxmox.com/pve-docs-7/api-viewer/)
- [PVE 8 API Viewer](https://pve.proxmox.com/pve-docs-8/api-viewer/)
- [current PVE 9 API Viewer](https://pve.proxmox.com/pve-docs/api-viewer/)
- [PBS 3 API Viewer](https://pbs.proxmox.com/docs-3/api-viewer/)
- [current PBS 4 API Viewer](https://pbs.proxmox.com/docs/api-viewer/)

The generated `apidoc.js` files behind those viewers are the official schema
inputs. The version-specific URLs are stable retrieval locations, but their
contents are not immutable. Therefore
[`proxmox-official-api-baseline.json`](proxmox-official-api-baseline.json)
records the retrieval date, HTTP validator metadata, and SHA-256 digest for
every reviewed major line. The baseline also stores a semantic projection of
only the operations that Hoddmímir deliberately supports.

For the mutable `pve-docs` and `docs` aliases, the checker additionally reads
the official documentation title/version marker and fails if it no longer
identifies PVE 9 or PBS 4. A future major release therefore cannot silently be
treated as the currently supported major even when its API remains additive.

## Compatibility policy

The checker implements a fail-closed baseline-subset comparison:

- a reviewed operation, field, enum value, type, format, bound, pattern, or
  request default may not disappear or change;
- additive response fields and optional request parameters are compatible,
  reported in bounded output, and do not fail the job;
- a new required request parameter or a new `required`, `requires`, or
  `conflicts` constraint is incompatible;
- every request schema is checked recursively: newly introduced bounds,
  lengths, patterns, constants, formats, closed enums, item/object constraints,
  and implicit or explicit required fields in nested objects are incompatible;
- response enum additions are incompatible unless their exact semantic
  location is listed under `openResponseEnums` after review of the concrete
  consumer; PBS `backup-type` is deliberately not open;
- a raw SHA-256 change is always reported even if the reviewed semantic surface
  remains compatible;
- operations not present in the explicit `capabilities` lists are ignored;
- the scheduled job never rewrites the baseline and never enables a new
  capability.

Incompatible drift exits nonzero and prints at most 100 precise semantic
locations. Compatible byte or schema additions remain visible in the job log
and GitHub step summary. Descriptions and presentation-only viewer data are not
part of the machine contract.

## Execution boundary

The dedicated workflow is triggered only by `schedule` and
`workflow_dispatch`. It has read-only repository permissions, uses no secrets,
and has no `push` or `pull_request` trigger. Normal PR CI therefore has no
dependency on Proxmox documentation availability or any live PVE/PBS host.
The downloader accepts only the five pinned official HTTPS URLs. Redirects are
rejected before a second request can be issued, even when the target is another
allowlisted host. Error messages never include URL userinfo, query data, redirect
targets, or transport exception details.

Run the deterministic policy tests offline:

```sh
make api-schema-drift-test
```

Run the official comparison manually when internet access is intentional:

```sh
python3 scripts/check-proxmox-api-schema-drift.py
```

## Reviewed baseline updates

An upstream hash change does not justify a baseline update by itself. Review
the controlled diff and the affected Hoddmímir adapters and contract fixtures
first. To prepare an update, download all five official `apidoc.js` files into
an isolated directory using the baseline IDs as filenames (`pve-7.js`,
`pve-8.js`, `pve-9.js`, `pbs-3.js`, and `pbs-4.js`). Then run:

```sh
python3 scripts/check-proxmox-api-schema-drift.py \
  --baseline docs/proxmox-official-api-baseline.json \
  --source-directory /path/to/reviewed-official-schemas \
  --refresh \
  --output /tmp/proxmox-official-api-baseline.json
```

The refresh operation updates hashes and semantic snapshots only for operations
already listed under `capabilities`; it cannot discover or add an operation.
For every schema it also requires:

- the official documentation page as `<id>.version.html`;
- a reviewed `<id>.metadata.json` sidecar with exactly `schemaUrl`,
  `documentationUrl`, `retrievedAt`, `lastModified`, `etag`, and `sha256`;
- a sidecar SHA-256 matching the downloaded bytes;
- the exact product/major URL pair and a matching official major-version marker.

The sidecar makes the refreshed provenance metadata deterministic instead of
sampling local time or trusting stale baseline headers. A minimal example is:

```json
{
  "schemaUrl": "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js",
  "documentationUrl": "https://pve.proxmox.com/pve-docs/",
  "retrievedAt": "2026-07-13",
  "lastModified": "Mon, 13 Jul 2026 08:00:00 GMT",
  "etag": "reviewed-http-etag",
  "sha256": "64-lowercase-hex-characters"
}
```

Adding a new capability requires a separate explicit code and fixture review,
an intentional allowlist edit, and a normal pull request. Copy the reviewed
temporary baseline into the repository only after that review.
