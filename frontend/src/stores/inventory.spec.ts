import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { InventoryResource } from "@/api/generated/types.gen";
import type { CursorPage } from "@/api/pagination";
import {
  OTHER_UUID,
  UUID,
  mockApi,
  resource,
  resourcePage,
} from "@/test/fixtures";
import { useInventoryStore } from "./inventory";

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve;
    reject = onReject;
  });
  return { promise, resolve, reject };
}

describe("InventoryStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt die erste Cursor-Seite", async () => {
    const api = mockApi();
    api.getResources = vi.fn().mockResolvedValue({
      items: [resource()],
      page: { limit: 25, count: 1, hasMore: true, nextCursor: "next" },
    });
    const store = useInventoryStore();
    await store.load(api);
    expect(store.items).toHaveLength(1);
    expect(store.page.nextCursor).toBe("next");
    expect(store.empty).toBe(false);
    expect(store.error).toBeNull();
  });

  it("setzt alle zulässigen Filter und sendet nur kompatible Parameter", () => {
    const store = useInventoryStore();
    store.setConnectionId(` ${UUID} `);
    store.setParentId(` ${OTHER_UUID} `);
    store.setGuestType("lxc");
    expect(store.query()).toEqual({
      kind: "pve_guest",
      inventoryState: "active",
      limit: 25,
      connectionId: UUID,
      parentId: OTHER_UUID,
      guestType: "lxc",
    });

    store.setKind("pve_cluster");
    expect(store.guestType).toBe("all");
    expect(store.parentId).toBe("");
    expect(store.query()).not.toHaveProperty("parentId");
    expect(store.query()).not.toHaveProperty("guestType");

    store.setParentId(OTHER_UUID);
    store.setGuestType("qemu");
    store.setKind("pve_guest");
    expect(store.parentId).toBe(OTHER_UUID);
    expect(store.guestType).toBe("qemu");

    store.setKind("pbs_server");
    store.setParentId(OTHER_UUID);
    expect(store.query()).not.toHaveProperty("parentId");
  });

  it("setzt Status, Gasttyp und Cursor stabil zurück", () => {
    const store = useInventoryStore();
    store.page = { limit: 25, count: 25, hasMore: true, nextCursor: "next" };
    store.setInventoryState("archived");
    expect(store.page.nextCursor).toBeNull();
    expect(store.inventoryState).toBe("archived");
    store.page = { limit: 25, count: 25, hasMore: true, nextCursor: "next" };
    store.setGuestType("qemu");
    expect(store.page.nextCursor).toBeNull();
  });

  it("lässt optionale leere Filter aus", () => {
    const store = useInventoryStore();
    expect(store.query()).not.toHaveProperty("connectionId");
    expect(store.query()).not.toHaveProperty("parentId");
    expect(store.query()).not.toHaveProperty("guestType");
  });

  it("leert alte Einträge und zeigt Fehler", async () => {
    const api = mockApi();
    const store = useInventoryStore();
    await store.load(api);
    expect(store.items).toHaveLength(1);
    api.getResources = vi.fn().mockRejectedValue(new Error("Backendfehler"));
    await store.load(api);
    expect(store.items).toEqual([]);
    expect(store.empty).toBe(true);
    expect(store.error).toBe("Backendfehler");
    expect(store.loading).toBe(false);
  });

  it("lädt Folgeseiten mit opaque Cursor und hängt sie an", async () => {
    const api = mockApi();
    api.getResources = vi
      .fn()
      .mockResolvedValueOnce({
        items: [resource("pve_node")],
        page: { limit: 25, count: 1, hasMore: true, nextCursor: "opaque" },
      })
      .mockResolvedValueOnce(resourcePage([resource("pve_storage")]));
    const store = useInventoryStore();
    await store.load(api);
    await store.loadMore(api);

    expect(api.getResources).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: "opaque" }),
    );
    expect(store.items.map(({ kind }) => kind)).toEqual([
      "pve_node",
      "pve_storage",
    ]);
    expect(store.page.hasMore).toBe(false);

    await store.loadMore(api);
    expect(api.getResources).toHaveBeenCalledTimes(2);
  });

  it("verwirft verspätete Erfolge und Fehler", async () => {
    const first = deferred<CursorPage<InventoryResource>>();
    const api = mockApi();
    api.getResources = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce(resourcePage([resource("pve_node")]));
    const store = useInventoryStore();
    const oldLoad = store.load(api);
    await store.load(api);
    first.resolve(resourcePage([resource("pve_storage")]));
    await oldLoad;
    expect(store.items[0]?.kind).toBe("pve_node");

    const oldFailure = deferred<CursorPage<InventoryResource>>();
    api.getResources = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(resourcePage([resource("pbs_server")]));
    const oldFailureLoad = store.load(api);
    await store.load(api);
    oldFailure.reject(new Error("zu spät"));
    await oldFailureLoad;
    expect(store.items[0]?.kind).toBe("pbs_server");
    expect(store.error).toBeNull();
  });
});
