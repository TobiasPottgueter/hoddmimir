import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ConnectionApi } from "@/api/connectionApi";
import type { ConnectionDetail } from "@/api/generated/types.gen";
import { UUID, OTHER_UUID } from "@/test/fixtures";
import { useAuthStore } from "@/stores/auth";
import { useConnectionsStore } from "./connections";

const detail: ConnectionDetail = {
  id: UUID,
  displayName: "PVE",
  product: "pve",
  enabled: false,
  revision: 1,
  detectedVersion: null,
  versionSupportStatus: "unknown",
  createdAt: "2026-07-13T00:00:00.000000Z",
  updatedAt: "2026-07-13T00:00:00.000000Z",
  onboardingState: null,
  endpoints: [],
  credentials: [],
};
const api = (): ConnectionApi => ({
  list: vi.fn().mockResolvedValue({
    items: [detail],
    page: { limit: 20, count: 1, hasMore: false, nextCursor: null },
  }),
  detail: vi.fn().mockResolvedValue(detail),
  update: vi.fn().mockResolvedValue({ status: "applied", revision: 2 }),
  disable: vi.fn().mockResolvedValue({ status: "applied", revision: 2 }),
  disableEndpoint: vi
    .fn()
    .mockResolvedValue({ status: "applied", revision: 2 }),
});
const authorize = () =>
  useAuthStore().$patch({
    principal: {
      id: UUID,
      username: "admin",
      permissions: ["backup_configuration.manage"],
    },
    csrfToken: "csrf",
    initialized: true,
  });
describe("Connections Store", () => {
  beforeEach(() => setActivePinia(createPinia()));
  it("lädt Liste und Detail nur mit Permission", async () => {
    authorize();
    const fake = api(),
      store = useConnectionsStore();
    await store.load(fake);
    expect(store.items).toEqual([detail]);
    await store.select(UUID, fake);
    expect(store.selected).toEqual(detail);
    expect(store.loading).toBe(false);
  });
  it("paginiert nur bei aktivem Folgekursor und hängt Details an", async () => {
    authorize();
    const fake = api();
    vi.mocked(fake.list)
      .mockResolvedValueOnce({
        items: [detail],
        page: { limit: 20, count: 1, hasMore: true, nextCursor: "next" },
      })
      .mockResolvedValueOnce({
        items: [{ ...detail, id: OTHER_UUID }],
        page: { limit: 20, count: 1, hasMore: false, nextCursor: null },
      });
    vi.mocked(fake.detail).mockImplementation(async (id) => ({
      ...detail,
      id,
    }));
    const store = useConnectionsStore();
    await store.load(fake);
    await store.loadMore(fake);
    expect(fake.list).toHaveBeenLastCalledWith({ limit: 20, cursor: "next" });
    expect(store.items.map((item) => item.id)).toEqual([UUID, OTHER_UUID]);
    await store.loadMore(fake);
    store.loading = true;
    await store.loadMore(fake);
    expect(fake.list).toHaveBeenCalledTimes(2);
  });
  it("verweigert Reads ohne Permission", async () => {
    const fake = api(),
      store = useConnectionsStore();
    await store.load(fake);
    await store.select(UUID, fake);
    expect(fake.list).not.toHaveBeenCalled();
    expect(store.error).toContain("backup_configuration.manage");
  });
  it("leert Daten bei Read-Fehlern", async () => {
    authorize();
    const fake = api(),
      store = useConnectionsStore();
    vi.mocked(fake.list).mockRejectedValueOnce(new Error("down"));
    await store.load(fake);
    expect(store.items).toEqual([]);
    vi.mocked(fake.detail).mockRejectedValueOnce(new Error("down"));
    await store.select(UUID, fake);
    expect(store.selected).toBeNull();
    expect(store.error).toContain("nicht sicher");
  });
  it("führt nur sichere Mutationen mit CSRF und Idempotenz aus", async () => {
    authorize();
    const fake = api(),
      store = useConnectionsStore(),
      key = () => "fixed";
    expect(
      await store.update(
        UUID,
        { expectedRevision: 1, displayName: "PVE" },
        fake,
        key,
      ),
    ).toBe(true);
    await store.disable(UUID, { expectedRevision: 1 }, fake, key);
    await store.disableEndpoint(
      UUID,
      OTHER_UUID,
      { expectedRevision: 1 },
      fake,
      key,
    );
    expect(fake.disableEndpoint).toHaveBeenCalledWith(
      UUID,
      OTHER_UUID,
      { expectedRevision: 1 },
      { csrfToken: "csrf", idempotencyKey: "fixed" },
    );
    expect(store.success).toContain("gespeichert");
  });
  it.each([
    [{ httpStatus: 400 }, "ungültig"],
    [{ httpStatus: 403 }, "Berechtigung"],
    [
      { httpStatus: 409, payload: { error: { currentRevision: 7 } } },
      "zwischenzeitlich",
    ],
    [
      {
        httpStatus: 422,
        payload: { error: { blockers: ["last_enabled_endpoint"] } },
      },
      "Sicherheitsregel",
    ],
    [{ httpStatus: 503 }, "nicht verfügbar"],
    [new Error("x"), "nicht angewendet"],
  ])("projiziert Commandfehler", async (caught, message) => {
    authorize();
    const fake = api();
    vi.mocked(fake.update).mockRejectedValueOnce(caught);
    const store = useConnectionsStore();
    expect(
      await store.update(
        UUID,
        { expectedRevision: 1, displayName: "PVE" },
        fake,
        () => "key",
      ),
    ).toBe(false);
    expect(store.error).toContain(message);
  });
  it("verweigert Mutationen ohne CSRF oder Permission", async () => {
    const fake = api(),
      store = useConnectionsStore();
    expect(await store.disable(UUID, { expectedRevision: 1 }, fake)).toBe(
      false,
    );
    expect(fake.disable).not.toHaveBeenCalled();
  });
  it("nutzt standardmäßig crypto.randomUUID", async () => {
    authorize();
    const fake = api(),
      store = useConnectionsStore(),
      random = vi
        .spyOn(crypto, "randomUUID")
        .mockReturnValue("00000000-0000-4000-8000-000000000000");
    await store.disable(UUID, { expectedRevision: 1 }, fake);
    expect(random).toHaveBeenCalled();
  });
});
