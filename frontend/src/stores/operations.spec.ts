import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type {
  CollectorScope,
  CollectorStatus,
} from "@/api/generated/types.gen";
import type { CursorPage } from "@/api/pagination";
import {
  OTHER_UUID,
  UUID,
  collectorScope,
  collectorStatus,
  mockApi,
} from "@/test/fixtures";
import { useOperationsStore } from "./operations";

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve;
    reject = onReject;
  });
  return { promise, resolve, reject };
}

describe("OperationsStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt Status und Collector-Läufe", async () => {
    const api = mockApi();
    const store = useOperationsStore();
    await store.load(api);
    expect(api.getCollectorRuns).toHaveBeenCalledWith({
      limit: 25,
    });
    expect(store.status).toEqual(collectorStatus);
    expect(store.runs).toHaveLength(1);
    expect(store.loading).toBe(false);
  });

  it("hängt Lauf-Folgeseiten über den opaque Cursor an", async () => {
    const api = mockApi();
    api.getCollectorRuns = vi
      .fn()
      .mockResolvedValueOnce({
        items: [],
        page: { limit: 25, count: 0, hasMore: true, nextCursor: "runs-next" },
      })
      .mockResolvedValueOnce({
        items: [],
        page: { limit: 25, count: 0, hasMore: false, nextCursor: null },
      });
    const store = useOperationsStore();
    await store.load(api);
    await store.loadMoreRuns(api);
    expect(api.getCollectorRuns).toHaveBeenLastCalledWith({
      limit: 25,
      cursor: "runs-next",
    });
    await store.loadMoreRuns(api);
    expect(api.getCollectorRuns).toHaveBeenCalledTimes(2);
  });

  it("meldet Ladefehler", async () => {
    const api = mockApi();
    api.getCollectorStatus = vi.fn().mockRejectedValue(new Error("kaputt"));
    const store = useOperationsStore();
    await store.load(api);
    expect(store.error).toBe("kaputt");
  });

  it("lädt Scopes für genau einen Lauf und zählt unvollständige", async () => {
    const api = mockApi();
    api.getCollectorScopes = vi
      .fn()
      .mockResolvedValueOnce({
        items: [collectorScope],
        page: { limit: 50, count: 1, hasMore: true, nextCursor: "scope-next" },
      })
      .mockResolvedValueOnce({
        items: [{ ...collectorScope, scopeKey: "node-a", status: "partial" }],
        page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
      });
    const store = useOperationsStore();
    await store.selectRun(UUID, api);
    expect(store.scopes).toHaveLength(1);
    expect(store.scopesPage.hasMore).toBe(true);
    await store.loadMoreScopes(api);
    expect(api.getCollectorScopes).toHaveBeenNthCalledWith(1, {
      runId: UUID,
      limit: 50,
    });
    expect(api.getCollectorScopes).toHaveBeenNthCalledWith(2, {
      runId: UUID,
      limit: 50,
      cursor: "scope-next",
    });
    expect(store.selectedRunId).toBe(UUID);
    expect(store.incompleteScopeCount).toBe(1);
    expect(store.scopesLoading).toBe(false);

    await store.loadMoreScopes(api);
    expect(api.getCollectorScopes).toHaveBeenCalledTimes(2);
  });

  it("lädt ohne ausgewählten Lauf keine Scope-Seite", async () => {
    const api = mockApi();
    const store = useOperationsStore();
    store.scopesPage = {
      limit: 50,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    await store.loadMoreScopes(api);
    expect(api.getCollectorScopes).not.toHaveBeenCalled();
  });

  it("leert Scopes vor dem Laden und meldet Scope-Fehler", async () => {
    const api = mockApi();
    const store = useOperationsStore();
    await store.selectRun(UUID, api);
    api.getCollectorScopes = vi
      .fn()
      .mockRejectedValue(new Error("scope kaputt"));
    await store.selectRun(OTHER_UUID, api);
    expect(store.scopes).toEqual([]);
    expect(store.scopesError).toBe("scope kaputt");
  });

  it("verwirft ältere Status- und Scope-Antworten", async () => {
    const oldStatus = deferred<CollectorStatus>();
    const api = mockApi();
    api.getCollectorStatus = vi
      .fn()
      .mockReturnValueOnce(oldStatus.promise)
      .mockResolvedValueOnce({ ...collectorStatus, generatedAt: OTHER_UUID });
    const store = useOperationsStore();
    const firstLoad = store.load(api);
    await store.load(api);
    oldStatus.resolve(collectorStatus);
    await firstLoad;
    expect(store.status?.generatedAt).toBe(OTHER_UUID);

    const oldScopes = deferred<CursorPage<CollectorScope>>();
    api.getCollectorScopes = vi
      .fn()
      .mockReturnValueOnce(oldScopes.promise)
      .mockResolvedValueOnce({
        items: [{ ...collectorScope, scopeKey: "new" }],
        page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
      });
    const firstScopes = store.selectRun(UUID, api);
    await store.selectRun(OTHER_UUID, api);
    oldScopes.resolve({
      items: [collectorScope],
      page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
    });
    await firstScopes;
    expect(store.scopes[0]?.scopeKey).toBe("new");
  });

  it("ignoriert verspätete Fehler", async () => {
    const oldStatus = deferred<CollectorStatus>();
    const api = mockApi();
    api.getCollectorStatus = vi
      .fn()
      .mockReturnValueOnce(oldStatus.promise)
      .mockResolvedValueOnce(collectorStatus);
    const store = useOperationsStore();
    const firstLoad = store.load(api);
    await store.load(api);
    oldStatus.reject(new Error("alt"));
    await firstLoad;
    expect(store.error).toBeNull();

    const oldScopes = deferred<CursorPage<CollectorScope>>();
    api.getCollectorScopes = vi
      .fn()
      .mockReturnValueOnce(oldScopes.promise)
      .mockResolvedValueOnce({
        items: [collectorScope],
        page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
      });
    const firstScopes = store.selectRun(UUID, api);
    await store.selectRun(OTHER_UUID, api);
    oldScopes.reject(new Error("alt"));
    await firstScopes;
    expect(store.scopesError).toBeNull();
  });
});
