# PVE contract fixture provenance

These fixtures are hand-constructed from the official Proxmox VE API Viewer
schemas and, for storage enrichment, the official `pve-storage` source. They
are complete `api2/json` envelopes, but they are **not captures from a live
PVE installation** and are **not release evidence**. Live,
read-only checks against the latest patched PVE 7, 8 and 9 releases remain a
separate release gate.

Retrieval date: **2026-07-10**

## Source assets

| Major | documentation set | schema asset | SHA-256 of downloaded bytes |
|---|---|---|---|
| 7 | 7.4 (2023-03-22) | <https://pve.proxmox.com/pve-docs-7/api-viewer/apidoc.js> | `125f0af24951e901800e49559593678edd95af66da27c88311faecda708ebaf1` |
| 8 | 8.4.0 (2025-04-09) | <https://pve.proxmox.com/pve-docs-8/api-viewer/apidoc.js> | `bbe03a42c55b3f9ae77a5b5216c1a8554f4fffd0f4b266848f4af26be295946e` |
| 9 | 9.2.3 (2026-07-03) | <https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js> | `f2b77b57c71f3781a0993cc5062940ef31e0843fd9a6bcfdb4de4dd2001d6d9e` |

The PVE 9 URL is mutable. The recorded hash identifies the exact asset used
for this fixture revision.

## Storage enrichment source boundary

The Viewer return schema for `GET /storage` declares only a small part of the
record that the official implementation returns. The `storage-config.json`
and `storage-node-*.json` fixtures therefore also follow the official
`pve-storage` implementation at these pinned commits:

- `stable-7`: `13f8b110bd90ecfddb9a84fb599ff08dbd3b1079`;
- `stable-8`: `1af4790d7ef4caacc9db10cc3c5f64575f13e966`;
- `master`: `d666ebd61a5cfcfbc6bec733754f87d56eddf985`.

The reviewed files at each commit are:

- `src/PVE/API2/Storage/Config.pm` for the ACL-filtered normalized config
  records and repeated global `storage.cfg` digest;
- `src/PVE/API2/Storage/Status.pm` for the node status fields and the
  server-side `activate_storage()` behavior;
- `src/PVE/Storage/PBSPlugin.pm` for PBS server, port, datastore, and namespace
  semantics.

The exact source links and the operational side-effect boundary are recorded
in [`docs/pve-storage-read-contract.md`](../../../../../docs/pve-storage-read-contract.md).
No synthetic canonical schema hash is claimed for implementation-only fields.
Storage IDs additionally follow the case-insensitive `pve-storage-id` grammar
pinned from `pve-common` at commits `c89e056` (PVE 7 line) and `74c2506`
(current line): `[a-z][a-z0-9._-]*[a-z0-9]` with `/i`. The original Section
header spelling is preserved rather than lowercased.

The public package-version grammar used in `version.json` was cross-checked on
the same retrieval date against the official `pve-manager` package indexes for
[PVE 7 / Bullseye](http://download.proxmox.com/debian/pve/dists/bullseye/pve-no-subscription/binary-amd64/Packages.gz),
[PVE 8 / Bookworm](http://download.proxmox.com/debian/pve/dists/bookworm/pve-no-subscription/binary-amd64/Packages.gz)
and [PVE 9 / Trixie](http://download.proxmox.com/debian/pve/dists/trixie/pve-no-subscription/binary-amd64/Packages.gz).
This preserves the PVE-7 `7.4-<revision>` form and the PVE-8/9 dotted patch
form while keeping repository IDs synthetic.

## Canonical endpoint schema hashes

Each endpoint hash is SHA-256 over compact UTF-8 JSON containing the endpoint
GET schema fields `method`, `parameters`, `permissions`, optional `protected`,
and `returns`. Object keys are sorted recursively; array order is preserved;
undefined fields are omitted. Serialization is ECMAScript `JSON.stringify`
without a trailing newline. The tree node is selected by its exact `path` and
then its exact `info.GET` member.

| Major | endpoint | fixtures | canonical schema SHA-256 |
|---|---|---|---|
| 7 | `GET /version` | `7/version.json` | `8d992953d1db7f572a713f5e01b8ca2c58e312591acdf2b6bfb30219a4fc4d1d` |
| 7 | `GET /access/permissions` | `7/access-permissions.json` | `a67e72f1f85b6f5ec1ff0837f28184e745e6f826defab6af274a0593cbdeca27` |
| 7 | `GET /cluster/status` | `7/cluster-status-clustered.json`, `7/cluster-status-standalone.json` | `4e93e631920828645b5ebc44a21720b88b93affeec94d91d7b5d8188f5e6e96b` |
| 7 | `GET /cluster/resources` | `7/cluster-resources.json`, `7/cluster-resources-standalone.json` | `7389aa80644355025d25df192c85dcd79099d1349884cb67fe6ac1fc559e930b` |
| 8 | `GET /version` | `8/version.json` | `befcb6a8e5a93b96c046dbe3d34d9e9ecbfceda16dc09c42c107174267692a64` |
| 8 | `GET /access/permissions` | `8/access-permissions.json` | `2d8354d03464e629d3c9f8fdebee5abf35720c2ff32235d0578c39c5319c76a8` |
| 8 | `GET /cluster/status` | `8/cluster-status-clustered.json`, `8/cluster-status-standalone.json` | `4e93e631920828645b5ebc44a21720b88b93affeec94d91d7b5d8188f5e6e96b` |
| 8 | `GET /cluster/resources` | `8/cluster-resources.json`, `8/cluster-resources-standalone.json` | `cc00af957d438103189eb450c6b8d9ba3eb8c597467a7ad515c9208fdeeb031c` |
| 9 | `GET /version` | `9/version.json` | `befcb6a8e5a93b96c046dbe3d34d9e9ecbfceda16dc09c42c107174267692a64` |
| 9 | `GET /access/permissions` | `9/access-permissions.json` | `2d8354d03464e629d3c9f8fdebee5abf35720c2ff32235d0578c39c5319c76a8` |
| 9 | `GET /cluster/status` | `9/cluster-status-clustered.json`, `9/cluster-status-standalone.json` | `4e93e631920828645b5ebc44a21720b88b93affeec94d91d7b5d8188f5e6e96b` |
| 9 | `GET /cluster/resources` | `9/cluster-resources.json`, `9/cluster-resources-standalone.json` | `ee572342048c90a7a91e49f54b6e2299d9c4f826eec1800a7409a5086e9caf4e` |

The following provenance-only Node.js program reproduces an endpoint hash
from an explicitly downloaded local `apidoc.js`. Set `file` to the matching
major asset and `endpoint` to one of the four exact paths in the table.

```js
const crypto = require('node:crypto');
const fs = require('node:fs');

const file = '/tmp/pve-9-apidoc.js';
const endpoint = '/cluster/resources';
const source = fs.readFileSync(file, 'utf8');
const marker = 'const apiSchema = ';
const start = source.indexOf(marker) + marker.length;

let depth = 0;
let end = -1;
let escaped = false;
let inString = false;
for (let index = start; index < source.length; index += 1) {
  const character = source[index];
  if (inString) {
    if (escaped) escaped = false;
    else if (character === '\\') escaped = true;
    else if (character === '"') inString = false;
    continue;
  }
  if (character === '"') inString = true;
  else if (character === '[') depth += 1;
  else if (character === ']' && --depth === 0) {
    end = index + 1;
    break;
  }
}

const tree = JSON.parse(source.slice(start, end));
const find = (nodes) => {
  for (const node of nodes) {
    if (node.path === endpoint) return node;
    const nested = node.children && find(node.children);
    if (nested) return nested;
  }
  return undefined;
};
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]),
    );
  }
  return value;
};

const get = find(tree).info.GET;
const selected = {
  method: get.method,
  parameters: get.parameters,
  permissions: get.permissions,
  protected: get.protected,
  returns: get.returns,
};
for (const key of Object.keys(selected)) {
  if (selected[key] === undefined) delete selected[key];
}
const canonicalJson = JSON.stringify(canonicalize(selected));
console.log(crypto.createHash('sha256').update(canonicalJson, 'utf8').digest('hex'));
```

These hashes are provenance metadata, not a network dependency of the regular
unit or contract test suite. Updating them is an explicit maintainer drift
review: fetch the official asset, verify its full-byte hash, reproduce all
four endpoint hashes for that major, review the semantic diff, then update
fixtures deliberately.

## Construction and sanitization rules

- Every file has exactly one top-level member, `data`, matching the API2 JSON
  envelope.
- For every major, `cluster-status-clustered.json` and
  `cluster-resources.json` form one coherent two-node scan. The corresponding
  `cluster-status-standalone.json` and `cluster-resources-standalone.json`
  form one coherent single-node scan; every guest and storage placement names
  that local standalone node.
- Host-like values use the reserved `.test` domain. IP addresses use RFC 5737
  documentation ranges. VMIDs, names, cluster names, repository IDs and
  measurements are synthetic.
- No real endpoint, node, cluster, account, token, cookie, CSRF value,
  certificate, fingerprint or other secret was used.
- PVE 7 intentionally has no `template` field in the resource index fixture.
  PVE 8 demonstrates additive guest metrics and `template`. PVE 9 demonstrates
  additional official fields, the `network` resource type and the intentionally
  unknown `future-observation` field.
- Unknown fields are present to test tolerant readers. They must never enable
  a capability without an explicit versioned capability decision.
- Each major's `storage-config.json`, `storage-node-a.json`, and
  `storage-node-b.json` form one coherent two-node enrichment read. Every
  config row carries the same synthetic global digest. The local and
  node-restricted backup storages are visible only on node A, the shared PBS
  storage is visible on both nodes, and disabled or non-backup definitions are
  absent from the filtered status fixtures.
- The inactive node-A observation deliberately reports PVE's initialized
  `0/0/0` capacity and must normalize to unavailable capacity. Unknown fields,
  PVE-7/8 `prune-backups`, PVE-8 `esxi` with its `import` content, a future
  PVE-9 plugin type, and neutral future fields remain non-capability-bearing
  compatibility probes. NFS is shared in every major fixture. No PVE-9
  `format` field is invented without requesting `format=1`. PVE-7 `glusterfs`
  is also normalized to shared; PVE-8 `esxi` remains non-shared unless that
  optional setting is explicitly configured.

See [`docs/pve-first-read-contract.md`](../../../../../docs/pve-first-read-contract.md)
for endpoint requirements, ACL-filter risk, TLS fingerprint semantics and the
cluster-identity boundary.
