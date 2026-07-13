import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ConfigurationApi } from "@/api/configurationApi";
import type { ConfiguredBackupTarget } from "@/api/generated/types.gen";
import type { CursorPage } from "@/api/pagination";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useConfiguredBackupTargetsStore } from "./configuredBackupTargets";

const target = {
  id: UUID,
  revision: 2,
  enabled: false,
  displayName: "Primärziel",
  connectionId: UUID,
  connectionName: "PVE Produktion",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageId: UUID,
  storageName: "pbs-primary",
  storageType: "pbs",
  minimumFreeBytes: "10000",
  fixedParallelLimit: 2,
  pbsConnectionId: UUID,
  pbsDatastoreId: OTHER_UUID,
  pbsNamespaceId: null,
  disabledAt: "2026-07-12T10:00:00.000000Z",
  allowedNodes: [{ id: OTHER_UUID, name: "pve-a" }],
  canEnable: false,
  blockers: ["configuration_incomplete"],
} satisfies ConfiguredBackupTarget;

function page(
  items: ConfiguredBackupTarget[] = [target],
  nextCursor: string | null = null,
): CursorPage<ConfiguredBackupTarget> {
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
  ...results: Array<CursorPage<ConfiguredBackupTarget>>
): ConfigurationApi {
  return {
    getBackupTargetCandidates: vi.fn().mockResolvedValue(page([])),
    getBackupTargets: vi
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

describe("ConfiguredBackupTargetsStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt und erweitert opaque Cursor-Seiten", async () => {
    const client = api(page([target], "next"), page([target]));
    const store = useConfiguredBackupTargetsStore();
    await store.load(client);
    await store.loadMore(client);

    expect(store.items).toHaveLength(2);
    expect(client.getBackupTargets).toHaveBeenLastCalledWith({
      limit: 20,
      cursor: "next",
    });
    await store.loadMore(client);
    expect(client.getBackupTargets).toHaveBeenCalledTimes(2);
  });

  it("lädt mehr als 20 Backupziele vollständig für Konfigurationsfelder", async () => {
    const firstPage = Array.from({ length: 20 }, (_, index) => ({
      ...target,
      id: `${index}`.padStart(32, "0"),
      displayName: `Ziel ${index}`,
    }));
    const lastTarget = {
      ...target,
      id: "f".repeat(32),
      displayName: "Ziel 21",
    };
    const client = api(page(firstPage, "targets-next"), page([lastTarget]));
    const store = useConfiguredBackupTargetsStore();

    await store.loadAllForConfiguration(client);

    expect(store.configurationItems).toHaveLength(21);
    expect(store.configurationItems.at(-1)?.displayName).toBe("Ziel 21");
    expect(client.getBackupTargets).toHaveBeenLastCalledWith({
      limit: 100,
      cursor: "targets-next",
    });
  });

  it("begrenzt die vollständige Backupziel-Pagination fail-closed", async () => {
    let pageNumber = 0;
    const client = api();
    client.getBackupTargets = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(page([], `target-page-${++pageNumber}`)),
      );
    const store = useConfiguredBackupTargetsStore();
    store.configurationItems = [target];

    await store.loadAllForConfiguration(client);

    expect(client.getBackupTargets).toHaveBeenCalledTimes(10_000);
    expect(store.configurationItems).toEqual([]);
    expect(store.configurationError).toContain("sichere Seitenlimit");
    expect(store.configurationLoading).toBe(false);
  });

  it("verwirft verspätete vollständige Konfigurationserfolge und -fehler", async () => {
    const oldSuccess = deferred<CursorPage<ConfiguredBackupTarget>>();
    const client = api();
    client.getBackupTargets = vi
      .fn()
      .mockReturnValueOnce(oldSuccess.promise)
      .mockResolvedValueOnce(page([target]));
    const store = useConfiguredBackupTargetsStore();

    const staleSuccess = store.loadAllForConfiguration(client);
    await store.loadAllForConfiguration(client);
    oldSuccess.resolve(page([]));
    await staleSuccess;
    expect(store.configurationItems).toEqual([target]);

    const oldFailure = deferred<CursorPage<ConfiguredBackupTarget>>();
    client.getBackupTargets = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(page([target]));
    const staleFailure = store.loadAllForConfiguration(client);
    await store.loadAllForConfiguration(client);
    oldFailure.reject(new Error("stale configuration"));
    await staleFailure;

    expect(store.configurationItems).toEqual([target]);
    expect(store.configurationError).toBeNull();
    expect(store.configurationLoading).toBe(false);
  });

  it("trimmt die Suche und übermittelt false als expliziten Statusfilter", () => {
    const store = useConfiguredBackupTargetsStore();
    store.page = { limit: 20, count: 20, hasMore: true, nextCursor: "old" };
    store.setSearch("  Primär  ");
    store.setEnabled(false);

    expect(store.search).toBe("Primär");
    expect(store.page.nextCursor).toBeNull();
    expect(store.query()).toEqual({
      limit: 20,
      search: "Primär",
      enabled: false,
    });
  });

  it.each([
    [400, "Filter oder der Seiten-Cursor"],
    [503, "vorübergehend nicht verfügbar"],
  ] as const)(
    "zeigt HTTP %s ohne Backend-Interna",
    async (httpStatus, text) => {
      const client = api();
      client.getBackupTargets = vi.fn().mockRejectedValue({
        httpStatus,
        payload: { error: { message: "SQL secret" } },
      });
      const store = useConfiguredBackupTargetsStore();
      await store.load(client);

      expect(store.error).toContain(text);
      expect(store.error).not.toContain("SQL secret");
      expect(store.items).toEqual([]);
      expect(store.loading).toBe(false);
    },
  );

  it("verwirft verspätete Erfolge und Fehler vollständig", async () => {
    const oldSuccess = deferred<CursorPage<ConfiguredBackupTarget>>();
    const client = api();
    client.getBackupTargets = vi
      .fn()
      .mockReturnValueOnce(oldSuccess.promise)
      .mockResolvedValueOnce(page([target]));
    const store = useConfiguredBackupTargetsStore();
    const oldLoad = store.load(client);
    await store.load(client);
    oldSuccess.resolve(page([]));
    await oldLoad;
    expect(store.items).toEqual([target]);

    const oldFailure = deferred<CursorPage<ConfiguredBackupTarget>>();
    client.getBackupTargets = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(page([target]));
    const oldFailureLoad = store.load(client);
    await store.load(client);
    oldFailure.reject({ httpStatus: 503 });
    await oldFailureLoad;
    expect(store.items).toEqual([target]);
    expect(store.error).toBeNull();
  });
});
