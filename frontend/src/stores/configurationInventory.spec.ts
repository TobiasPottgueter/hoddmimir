import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { InventoryApi } from "@/api/inventoryApi";
import type {
  InventoryResource,
  PveClusterResource,
  PveGuestResource,
  PveNodeResource,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useConfigurationInventoryStore } from "./configurationInventory";

const base = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  parentId: OTHER_UUID,
  displayName: "resource",
  inventoryState: "active",
  firstSeenAt: "2026-07-12T10:00:00.000000Z",
  lastSeenAt: "2026-07-12T10:00:00.000000Z",
  archivedAt: null,
  stateObservedAt: null,
} as const;
const cluster: PveClusterResource = {
  ...base,
  parentId: null,
  kind: "pve_cluster",
  attributes: { topology: "clustered" },
};
const node: PveNodeResource = {
  ...base,
  kind: "pve_node",
  attributes: { apiStatus: "online" },
};
const guest = (guestType: "qemu" | "lxc"): PveGuestResource => ({
  ...base,
  id: guestType === "qemu" ? UUID : OTHER_UUID,
  kind: "pve_guest",
  attributes: {
    guestType,
    vmid: guestType === "qemu" ? 101 : 102,
    template: false,
    nodeId: UUID,
    nodeName: "pve-a",
  },
});
const page = (
  items: InventoryResource[],
  nextCursor: string | null = null,
) => ({
  items,
  page: {
    limit: 100,
    count: items.length,
    hasMore: nextCursor !== null,
    nextCursor,
  },
});

function api(results: InventoryResource[][]): InventoryApi {
  return {
    getOverview: vi.fn(),
    getResources: vi
      .fn()
      .mockImplementation(() => Promise.resolve(page(results.shift() ?? []))),
    getCollectorStatus: vi.fn(),
    getCollectorRuns: vi.fn(),
    getCollectorScopes: vi.fn(),
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve;
    reject = onReject;
  });
  return { promise, resolve, reject };
}

describe("ConfigurationInventoryStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt bounded Cluster sowie konkrete Nodes, QEMU-VMs und LXC-Container", async () => {
    const client = api([
      [cluster, node],
      [node, cluster],
      [guest("qemu"), node],
      [guest("lxc"), cluster],
    ]);
    const store = useConfigurationInventoryStore();
    await store.loadClusters(client);
    await store.loadHierarchy(UUID, OTHER_UUID, client);

    expect(store.clusters).toEqual([cluster]);
    expect(store.nodes).toEqual([node]);
    expect(store.guests.map((item) => item.attributes.guestType)).toEqual([
      "qemu",
      "lxc",
    ]);
    expect(client.getResources).toHaveBeenNthCalledWith(2, {
      kind: "pve_node",
      connectionId: UUID,
      parentId: OTHER_UUID,
      inventoryState: "active",
      limit: 100,
    });
  });

  it("maskiert Fehler und leert nur den betroffenen Inventarstand", async () => {
    const client = api([]);
    client.getResources = vi.fn().mockRejectedValue(new TypeError("network"));
    const store = useConfigurationInventoryStore();
    store.clusters = [cluster];
    await store.loadClusters(client);
    expect(store.clusters).toEqual([]);
    expect(store.error).toContain("nicht erreichbar");

    store.nodes = [node];
    store.guests = [guest("qemu")];
    await store.loadHierarchy(UUID, OTHER_UUID, client);
    expect(store.nodes).toEqual([]);
    expect(store.guests).toEqual([]);
    expect(store.loading).toBe(false);
  });

  it("lädt alle Cursor-Seiten für Nodes, QEMU-VMs und LXC-Container", async () => {
    const secondNode = { ...node, id: OTHER_UUID, displayName: "pve-b" };
    const secondQemu = {
      ...guest("qemu"),
      id: OTHER_UUID,
      displayName: "qemu-202",
      attributes: { ...guest("qemu").attributes, vmid: 202 },
    };
    const secondLxc = {
      ...guest("lxc"),
      id: UUID,
      displayName: "lxc-203",
      attributes: { ...guest("lxc").attributes, vmid: 203 },
    };
    const client = api([]);
    client.getResources = vi.fn().mockImplementation((query) => {
      if (query.kind === "pve_node") {
        return Promise.resolve(
          query.cursor === undefined
            ? page([node], "nodes-next")
            : page([secondNode]),
        );
      }
      if (query.guestType === "qemu") {
        return Promise.resolve(
          query.cursor === undefined
            ? page([guest("qemu")], "qemu-next")
            : page([secondQemu]),
        );
      }
      return Promise.resolve(
        query.cursor === undefined
          ? page([guest("lxc")], "lxc-next")
          : page([secondLxc]),
      );
    });
    const store = useConfigurationInventoryStore();

    await store.loadHierarchy(UUID, OTHER_UUID, client);

    expect(store.nodes.map((item) => item.displayName)).toEqual([
      "resource",
      "pve-b",
    ]);
    expect(store.guests.map((item) => item.attributes.vmid)).toEqual([
      101, 202, 102, 203,
    ]);
    expect(client.getResources).toHaveBeenCalledTimes(6);
    expect(client.getResources).toHaveBeenCalledWith(
      expect.objectContaining({ kind: "pve_guest", cursor: "qemu-next" }),
    );
  });

  it("bricht bei einer nicht fortschreitenden Cursor-Kette fail-closed ab", async () => {
    const client = api([]);
    client.getResources = vi.fn().mockResolvedValue(page([cluster], "same"));
    const store = useConfigurationInventoryStore();

    await store.loadClusters(client);

    expect(store.clusters).toEqual([]);
    expect(store.error).toContain("keinen Fortschritt");
    expect(client.getResources).toHaveBeenCalledTimes(2);
  });

  it("begrenzt auch eine fortschreitende Inventar-Pagination fail-closed", async () => {
    let pageNumber = 0;
    const client = api([]);
    client.getResources = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(page([], `inventory-page-${++pageNumber}`)),
      );
    const store = useConfigurationInventoryStore();

    await store.loadClusters(client);

    expect(client.getResources).toHaveBeenCalledTimes(10_000);
    expect(store.clusters).toEqual([]);
    expect(store.error).toContain("sichere Seitenlimit");
    expect(store.loading).toBe(false);
  });

  it("verwirft veraltete Erfolge und Fehler beider Ladepfade", async () => {
    const oldCluster = deferred<ReturnType<typeof page>>();
    const oldHierarchy = deferred<ReturnType<typeof page>>();
    const client = api([[cluster]]);
    client.getResources = vi
      .fn()
      .mockReturnValueOnce(oldCluster.promise)
      .mockResolvedValueOnce(page([cluster]));
    const store = useConfigurationInventoryStore();
    const staleCluster = store.loadClusters(client);
    await store.loadClusters(client);
    oldCluster.resolve(page([]));
    await staleCluster;
    expect(store.clusters).toEqual([cluster]);

    const rejectedCluster = deferred<ReturnType<typeof page>>();
    client.getResources = vi
      .fn()
      .mockReturnValueOnce(rejectedCluster.promise)
      .mockResolvedValueOnce(page([cluster]));
    const staleClusterFailure = store.loadClusters(client);
    await store.loadClusters(client);
    rejectedCluster.reject(new Error("stale cluster"));
    await staleClusterFailure;
    expect(store.error).toBeNull();

    client.getResources = vi
      .fn()
      .mockReturnValueOnce(oldHierarchy.promise)
      .mockResolvedValueOnce(page([guest("qemu")]))
      .mockResolvedValueOnce(page([guest("lxc")]))
      .mockResolvedValueOnce(page([node]))
      .mockResolvedValueOnce(page([guest("qemu")]))
      .mockResolvedValueOnce(page([guest("lxc")]));
    const staleHierarchy = store.loadHierarchy(UUID, OTHER_UUID, client);
    await store.loadHierarchy(UUID, OTHER_UUID, client);
    oldHierarchy.reject(new Error("stale"));
    await staleHierarchy;
    expect(store.nodes).toEqual([node]);
    expect(store.guests).toHaveLength(2);
    expect(store.error).toBeNull();

    const successfulOldHierarchy = deferred<ReturnType<typeof page>>();
    client.getResources = vi
      .fn()
      .mockReturnValueOnce(successfulOldHierarchy.promise)
      .mockResolvedValueOnce(page([guest("qemu")]))
      .mockResolvedValueOnce(page([guest("lxc")]))
      .mockResolvedValueOnce(page([node]))
      .mockResolvedValueOnce(page([guest("qemu")]))
      .mockResolvedValueOnce(page([guest("lxc")]));
    const oldSuccessfulHierarchy = store.loadHierarchy(
      UUID,
      OTHER_UUID,
      client,
    );
    await store.loadHierarchy(UUID, OTHER_UUID, client);
    successfulOldHierarchy.resolve(page([]));
    await oldSuccessfulHierarchy;
    expect(store.nodes).toEqual([node]);
  });
});
