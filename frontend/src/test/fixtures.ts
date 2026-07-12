import { vi } from "vitest";

import type { InventoryApi } from "@/api/inventoryApi";
import type { CursorPage } from "@/api/pagination";
import type {
  CollectorRun,
  CollectorScope,
  CollectorStatus,
  InventoryOverview,
  InventoryResource,
  InventoryResourceKind,
  PbsBackupGroupResource,
  PbsNamespaceResource,
  PbsSnapshotResource,
} from "@/api/generated/types.gen";

export const UUID = "00112233-4455-6677-8899-aabbccddeeff";
export const OTHER_UUID = "11112233-4455-6677-8899-aabbccddeeff";

export function resource(
  kind: InventoryResourceKind = "pve_guest",
  attributes: Record<string, unknown> = {},
): InventoryResource {
  return {
    id: UUID,
    kind,
    connectionId: UUID,
    connectionName: "Lab API",
    parentId:
      kind === "pve_cluster" || kind === "pbs_server" ? null : OTHER_UUID,
    displayName: "Lab resource",
    inventoryState: "active",
    firstSeenAt: "2026-07-12T09:00:00.000000Z",
    lastSeenAt: "2026-07-12T10:00:00.000000Z",
    archivedAt: null,
    stateObservedAt: "2026-07-12T10:00:00.000000Z",
    attributes,
  } as unknown as InventoryResource;
}

export const PBS_DATASTORE_UUID = "22222222-2222-4222-8222-222222222222";
export const PBS_NAMESPACE_UUID = "33333333-3333-4333-8333-333333333333";
export const PBS_GROUP_UUID = "44444444-4444-4444-8444-444444444444";

export function pbsNamespace(
  overrides: Partial<PbsNamespaceResource> = {},
): PbsNamespaceResource {
  return {
    id: PBS_NAMESPACE_UUID,
    kind: "pbs_namespace",
    connectionId: UUID,
    connectionName: "PBS Lab",
    parentId: PBS_DATASTORE_UUID,
    displayName: "tenant/acme",
    inventoryState: "active",
    firstSeenAt: "2026-07-12T09:00:00.000000Z",
    lastSeenAt: "2026-07-12T10:00:00.000000Z",
    archivedAt: null,
    stateObservedAt: null,
    attributes: {
      datastoreId: PBS_DATASTORE_UUID,
      namespacePath: "tenant/acme",
      namespaceDepth: 2,
      parentNamespaceId: null,
    },
    ...overrides,
  };
}

export function pbsBackupGroup(): PbsBackupGroupResource {
  return {
    id: PBS_GROUP_UUID,
    kind: "pbs_backup_group",
    connectionId: UUID,
    connectionName: "PBS Lab",
    parentId: PBS_NAMESPACE_UUID,
    displayName: "vm/101",
    inventoryState: "active",
    firstSeenAt: "2026-07-12T09:00:00.000000Z",
    lastSeenAt: "2026-07-12T10:00:00.000000Z",
    archivedAt: null,
    stateObservedAt: null,
    attributes: {
      namespaceId: PBS_NAMESPACE_UUID,
      backupType: "vm",
      backupId: "101",
    },
  };
}

export function pbsSnapshot(): PbsSnapshotResource {
  return {
    id: "55555555-5555-4555-8555-555555555555",
    kind: "pbs_snapshot",
    connectionId: UUID,
    connectionName: "PBS Lab",
    parentId: PBS_GROUP_UUID,
    displayName: "2026-07-12T09:30:00.000000Z",
    inventoryState: "active",
    firstSeenAt: "2026-07-12T09:31:00.000000Z",
    lastSeenAt: "2026-07-12T10:00:00.000000Z",
    archivedAt: null,
    stateObservedAt: null,
    attributes: {
      backupTime: "2026-07-12T09:30:00.000000Z",
      protected: true,
      sizeBytes: 1_073_741_824,
      verificationState: "ok",
    },
  };
}

export const overview: InventoryOverview = {
  generatedAt: "2026-07-12T10:00:00.000000Z",
  latestInventoryAt: "2026-07-12T10:00:00.000000Z",
  counts: {
    pveConnections: 1,
    pbsConnections: 1,
    pveClusters: 1,
    pveNodes: 2,
    qemuGuests: 3,
    lxcGuests: 1,
    pveStorages: 1,
    pbsServers: 1,
    pbsDatastores: 1,
    pbsNamespaces: 2,
    pbsBackupGroups: 1,
    pbsSnapshots: 4,
  },
};

export function resourcePage(
  items = [resource()],
): CursorPage<InventoryResource> {
  return {
    items,
    page: {
      limit: 25,
      count: items.length,
      hasMore: false,
      nextCursor: null,
    },
  };
}

export const collectorStatus: CollectorStatus = {
  generatedAt: "2026-07-12T10:00:00.000000Z",
  schedule: {
    configured: true,
    intervalSeconds: 120,
    nextScanAt: "2026-07-12T10:02:00.000000Z",
    lastCycleStartedAt: "2026-07-12T09:59:00.000000Z",
    lastCycleFinishedAt: "2026-07-12T10:00:00.000000Z",
    leaseActive: false,
    leaseExpiresAt: null,
  },
  heartbeat: {
    status: "ready",
    startedAt: "2026-07-12T08:00:00.000000Z",
    fresh: true,
    heartbeatAt: "2026-07-12T10:00:00.000000Z",
    expiresAt: "2026-07-12T10:01:00.000000Z",
    currentActivity: null,
    nextActionAt: "2026-07-12T10:02:00.000000Z",
    buildVersion: "test-build",
  },
};

export const collectorRun: CollectorRun = {
  id: UUID,
  connectionId: UUID,
  connectionName: "Lab API",
  product: "pve",
  status: "succeeded",
  authoritative: true,
  startedAt: "2026-07-12T09:59:00.000000Z",
  finishedAt: "2026-07-12T10:00:00.000000Z",
  appliedAt: "2026-07-12T10:00:00.000000Z",
  nodesSeen: 2,
  guestsSeen: 3,
  storagesSeen: 1,
  errorCode: null,
};

export const collectorScope: CollectorScope = {
  runId: UUID,
  scopeType: "pve_guests",
  scopeKey: "@installation",
  status: "complete",
  observedAt: "2026-07-12T10:00:00.000000Z",
  errorCode: null,
};

export function mockApi(): InventoryApi {
  return {
    getOverview: vi.fn().mockResolvedValue(overview),
    getResources: vi.fn().mockResolvedValue(resourcePage()),
    getCollectorStatus: vi.fn().mockResolvedValue(collectorStatus),
    getCollectorRuns: vi.fn().mockResolvedValue({
      items: [collectorRun],
      page: { limit: 25, count: 1, hasMore: false, nextCursor: null },
    }),
    getCollectorScopes: vi.fn().mockResolvedValue({
      items: [collectorScope],
      page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
    }),
  };
}
