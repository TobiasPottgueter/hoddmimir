import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  OTHER_UUID,
  mockApi,
  overview,
  resource,
  resourcePage,
} from "@/test/fixtures";
import { useSystemsStore } from "./systems";

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve;
    reject = onReject;
  });
  return { promise, resolve, reject };
}

describe("SystemsStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt Übersicht, PVE-Cluster und PBS-Server gemeinsam", async () => {
    const api = mockApi();
    api.getResources = vi
      .fn()
      .mockResolvedValueOnce(resourcePage([resource("pve_cluster")]))
      .mockResolvedValueOnce(resourcePage([resource("pbs_server")]));
    const store = useSystemsStore();

    await store.load(api);

    expect(store.overview).toEqual(overview);
    expect(store.pveClusters).toHaveLength(1);
    expect(store.pbsServers).toHaveLength(1);
    expect(store.empty).toBe(false);
    expect(store.loading).toBe(false);
    expect(store.error).toBeNull();
  });

  it("folgt allen opaque System-Cursorseiten ohne Abschneiden", async () => {
    const api = mockApi();
    api.getResources = vi.fn().mockImplementation(({ kind, cursor }) => {
      if (kind === "pve_cluster" && cursor === undefined) {
        return Promise.resolve({
          items: [resource("pve_cluster")],
          page: {
            limit: 100,
            count: 1,
            hasMore: true,
            nextCursor: "clusters-next",
          },
        });
      }
      if (kind === "pve_cluster" && cursor === "clusters-next") {
        return Promise.resolve(resourcePage([resource("pve_cluster")]));
      }
      return Promise.resolve(resourcePage([resource("pbs_server")]));
    });
    const store = useSystemsStore();

    await store.load(api);

    expect(store.pveClusters).toHaveLength(2);
    expect(store.pbsServers).toHaveLength(1);
    expect(api.getResources).toHaveBeenCalledWith(
      expect.objectContaining({
        kind: "pve_cluster",
        cursor: "clusters-next",
      }),
    );
  });

  it("zeigt API-Fehler und den Leerzustand", async () => {
    const api = mockApi();
    api.getOverview = vi.fn().mockRejectedValue(new TypeError("offline"));
    const store = useSystemsStore();

    await store.load(api);

    expect(store.empty).toBe(true);
    expect(store.error).toContain("nicht erreichbar");
  });

  it("verwirft verspätete Antworten eines älteren Requests", async () => {
    const first = deferred<typeof overview>();
    const api = mockApi();
    api.getOverview = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce({ ...overview, generatedAt: OTHER_UUID });
    const store = useSystemsStore();

    const oldLoad = store.load(api);
    await store.load(api);
    first.resolve(overview);
    await oldLoad;

    expect(store.overview?.generatedAt).toBe(OTHER_UUID);
    expect(store.loading).toBe(false);
  });

  it("ignoriert auch verspätete Fehler eines älteren Requests", async () => {
    const first = deferred<typeof overview>();
    const api = mockApi();
    api.getOverview = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce(overview);
    const store = useSystemsStore();
    const oldLoad = store.load(api);
    await store.load(api);
    first.reject(new Error("alt"));
    await oldLoad;
    expect(store.error).toBeNull();
  });
});
