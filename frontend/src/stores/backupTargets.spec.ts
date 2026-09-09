import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ConfigurationApi } from "@/api/configurationApi";
import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import type { CursorPage } from "@/api/pagination";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import {
  configurationErrorMessage,
  useBackupTargetsStore,
} from "./backupTargets";

const candidate = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageName: "backup",
  storageType: "dir",
  shared: true,
  inventoryState: "active",
  observedAt: "2026-07-12T10:00:00.000000Z",
  canEnable: false,
  executor: {
    status: "requires_target_configuration",
    targetCount: 0,
    expectedNodeCount: 0,
    observedNodeCount: 0,
    vmBackupAuthorized: null,
    datastoreAllocateAuthorized: null,
    authorized: null,
    freshness: "missing",
    observedAt: null,
    blockers: ["executor_evidence_missing"],
  },
  nodes: [],
  pbs: null,
  blockers: ["storage_inventory_evidence_missing"],
} satisfies BackupTargetCandidate;

function page(
  items: BackupTargetCandidate[] = [candidate],
  nextCursor: string | null = null,
): CursorPage<BackupTargetCandidate> {
  return {
    items,
    page: {
      limit: 20,
      count: items.length,
      hasMore: nextCursor !== null,
      nextCursor,
    },
  };
}

function api(
  ...results: Array<CursorPage<BackupTargetCandidate>>
): ConfigurationApi {
  return {
    getBackupTargets: vi.fn().mockResolvedValue(page([])),
    getBackupTargetCandidates: vi
      .fn()
      .mockImplementation(() => Promise.resolve(results.shift() ?? page([]))),
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

describe("BackupTargetsStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt und erweitert opaque Cursor-Seiten", async () => {
    const client = api(page([candidate], "next"), page([candidate]));
    const store = useBackupTargetsStore();
    await store.load(client);
    await store.loadMore(client);

    expect(store.items).toHaveLength(2);
    expect(client.getBackupTargetCandidates).toHaveBeenLastCalledWith({
      limit: 20,
      cursor: "next",
    });
    await store.loadMore(client);
    expect(client.getBackupTargetCandidates).toHaveBeenCalledTimes(2);

    store.loading = true;
    await store.loadMore(client);
    expect(client.getBackupTargetCandidates).toHaveBeenCalledTimes(2);
  });

  it("trimmt Filter und verwirft den alten Cursor bei jeder Filteränderung", () => {
    const store = useBackupTargetsStore();
    store.page = { limit: 20, count: 20, hasMore: true, nextCursor: "old" };
    store.setConnectionId(` ${UUID} `);
    expect(store.connectionId).toBe(UUID);
    expect(store.page.nextCursor).toBeNull();

    store.page = { limit: 20, count: 20, hasMore: true, nextCursor: "again" };
    store.setClusterId(` ${OTHER_UUID} `);
    expect(store.clusterId).toBe(OTHER_UUID);
    expect(store.page.nextCursor).toBeNull();
    expect(store.query()).toEqual({
      limit: 20,
      connectionId: UUID,
      clusterId: OTHER_UUID,
    });
  });

  it.each([
    [400, "Filter oder der Seiten-Cursor"],
    [503, "vorübergehend nicht verfügbar"],
  ] as const)(
    "zeigt HTTP %s ohne Backend-Interna",
    async (httpStatus, text) => {
      const store = useBackupTargetsStore();
      const client: ConfigurationApi = {
        getBackupTargets: vi.fn().mockResolvedValue(page([])),
        getBackupTargetCandidates: vi.fn().mockRejectedValue({
          httpStatus,
          payload: { error: { message: "SQL secret" } },
        }),
      };
      await store.load(client);
      expect(store.error).toContain(text);
      expect(store.error).not.toContain("SQL secret");
      expect(store.items).toEqual([]);
      expect(store.loading).toBe(false);
    },
  );

  it("fällt für unbekannte Fehler auf die gemeinsame sichere Meldung zurück", () => {
    expect(configurationErrorMessage(null)).toBe(
      "Die Daten konnten nicht geladen werden.",
    );
    expect(configurationErrorMessage({ httpStatus: "503" })).toBe(
      "Die Daten konnten nicht geladen werden.",
    );
  });

  it("verwirft verspätete Erfolge und Fehler vollständig", async () => {
    const oldSuccess = deferred<CursorPage<BackupTargetCandidate>>();
    const client: ConfigurationApi = {
      getBackupTargets: vi.fn().mockResolvedValue(page([])),
      getBackupTargetCandidates: vi
        .fn()
        .mockReturnValueOnce(oldSuccess.promise)
        .mockResolvedValueOnce(page([candidate])),
    };
    const store = useBackupTargetsStore();
    const oldLoad = store.load(client);
    await store.load(client);
    oldSuccess.resolve(page([]));
    await oldLoad;
    expect(store.items).toEqual([candidate]);

    const oldFailure = deferred<CursorPage<BackupTargetCandidate>>();
    client.getBackupTargetCandidates = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(page([candidate]));
    const oldFailureLoad = store.load(client);
    await store.load(client);
    oldFailure.reject({ httpStatus: 503 });
    await oldFailureLoad;
    expect(store.items).toEqual([candidate]);
    expect(store.error).toBeNull();
  });
});
