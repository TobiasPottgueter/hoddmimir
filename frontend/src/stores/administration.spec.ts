import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdministrationApi } from "@/api/administrationApi";
import type { AuthPermission } from "@/api/authApi";
import type {
  AdministrationAuditEvent,
  AdministrationRole,
  AdministrationUser,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useAuthStore } from "@/stores/auth";
import { useAdministrationStore } from "./administration";

const page = { limit: 50, count: 1, hasMore: false, nextCursor: null };
const user: AdministrationUser = {
  id: UUID,
  username: "ada",
  displayName: "Ada Lovelace",
  enabled: true,
  revision: 3,
  roles: ["admin"],
  createdAt: "2026-07-12T10:00:00.000000Z",
  updatedAt: "2026-07-12T10:00:00.000000Z",
  disabledAt: null,
  lastLoginAt: null,
};
const role: AdministrationRole = {
  id: OTHER_UUID,
  name: "admin",
  displayName: "Administrator",
  permissions: ["security.manage", "audit.read"],
};
const auditEvent: AdministrationAuditEvent = {
  id: OTHER_UUID,
  occurredAt: "2026-07-12T10:00:00.000000Z",
  actorUserId: UUID,
  actorSessionId: null,
  eventType: "user_created",
  outcome: "succeeded",
  subjectType: "user",
  subjectId: UUID,
  reasonCode: null,
  correlationId: UUID,
};

function fakeApi(): AdministrationApi {
  return {
    health: vi.fn().mockResolvedValue({
      status: "ok",
      checkedAt: "2026-07-13T00:00:00.000000Z",
      checks: { database_schema: { status: "ready" } },
    }),
    users: vi.fn().mockResolvedValue({ items: [user], page }),
    roles: vi.fn().mockResolvedValue({ items: [role], page }),
    audit: vi.fn().mockResolvedValue({ items: [auditEvent], page }),
    auditEvent: vi.fn().mockResolvedValue(auditEvent),
    createUser: vi.fn().mockResolvedValue({ status: "applied", revision: 1 }),
    updateUser: vi.fn().mockResolvedValue({ status: "applied", revision: 4 }),
    disableUser: vi.fn().mockResolvedValue({ status: "applied", revision: 4 }),
    replaceRoles: vi.fn().mockResolvedValue({ status: "applied", revision: 4 }),
  };
}

function authorize(
  permissions: AuthPermission[] = ["security.manage", "audit.read"],
): void {
  useAuthStore().$patch({
    principal: { id: UUID, username: "admin", permissions: [...permissions] },
    csrfToken: "csrf",
    initialized: true,
  });
}

describe("Administration Store", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt Benutzer, Rollen und Audit-Ereignis einschließlich Cursor", async () => {
    authorize();
    const api = fakeApi();
    const store = useAdministrationStore();
    await store.loadUsers({ limit: 50, search: "ada" }, api);
    await store.loadRoles(api);
    await store.loadAudit({ limit: 50 }, api);
    await store.selectAuditEvent(OTHER_UUID, api);

    expect(store.users).toEqual([user]);
    expect(store.roles).toEqual([role]);
    expect(store.auditEvents).toEqual([auditEvent]);
    expect(store.selectedAuditEvent).toEqual(auditEvent);
    expect(store.usersCursor).toBeNull();
    expect(store.auditCursor).toBeNull();
    expect(store.loading).toBe(false);
  });

  it("bindet Load-more an denselben Filter und setzt Cursor bei Filterwechsel zurück", async () => {
    authorize();
    const api = fakeApi();
    vi.mocked(api.users)
      .mockResolvedValueOnce({
        items: [user],
        page: { ...page, hasMore: true, nextCursor: "users-next" },
      })
      .mockResolvedValueOnce({ items: [{ ...user, id: OTHER_UUID }], page })
      .mockResolvedValueOnce({ items: [], page: { ...page, count: 0 } });
    vi.mocked(api.audit)
      .mockResolvedValueOnce({
        items: [auditEvent],
        page: { ...page, hasMore: true, nextCursor: "audit-next" },
      })
      .mockResolvedValueOnce({ items: [], page: { ...page, count: 0 } });
    const store = useAdministrationStore();
    const userFilter = { limit: 50, search: "ada", enabled: true };
    const auditFilter = {
      limit: 50,
      eventType: "user_created" as const,
      outcome: "succeeded" as const,
    };

    await store.loadUsers(userFilter, api);
    await store.loadMoreUsers(userFilter, api);
    expect(api.users).toHaveBeenLastCalledWith({
      ...userFilter,
      cursor: "users-next",
    });
    expect(store.users).toHaveLength(2);
    await store.loadUsers({ limit: 50, enabled: false }, api);
    expect(store.usersCursor).toBeNull();
    expect(store.users).toEqual([]);
    await store.loadMoreUsers(userFilter, api);

    await store.loadAudit(auditFilter, api);
    await store.loadMoreAudit(auditFilter, api);
    expect(api.audit).toHaveBeenLastCalledWith({
      ...auditFilter,
      cursor: "audit-next",
    });
    expect(store.auditCursor).toBeNull();
    await store.loadMoreAudit(auditFilter, api);
  });

  it("paginiert Rollen und überspringt erschöpfte Folgeseiten", async () => {
    authorize();
    const api = fakeApi();
    vi.mocked(api.roles)
      .mockResolvedValueOnce({
        items: [role],
        page: { ...page, hasMore: true, nextCursor: "roles-next" },
      })
      .mockResolvedValueOnce({ items: [{ ...role, id: UUID }], page });
    const store = useAdministrationStore();
    await store.loadRoles(api);
    await store.loadMoreRoles(api);
    expect(api.roles).toHaveBeenLastCalledWith(50, "roles-next");
    expect(store.roles).toHaveLength(2);
    await store.loadMoreRoles(api);
    expect(api.roles).toHaveBeenCalledTimes(2);
  });

  it("lädt echte Readiness ohne Secrets und behandelt Ausfälle geschlossen", async () => {
    const store = useAdministrationStore();
    const api = fakeApi();
    await store.loadHealth(api);
    expect(store.health?.checks.database_schema?.status).toBe("ready");
    expect(store.healthLoading).toBe(false);
    vi.mocked(api.health).mockRejectedValueOnce(new Error("down"));
    await store.loadHealth(api);
    expect(store.health).toBeNull();
    expect(store.healthError).toContain("nicht erreichbar");
  });

  it("verweigert Reads ohne zugehörige Permission, ohne die API aufzurufen", async () => {
    authorize([]);
    const api = fakeApi();
    const store = useAdministrationStore();
    await store.loadUsers(undefined, api);
    await store.loadRoles(api);
    await store.loadAudit(undefined, api);
    await store.selectAuditEvent(OTHER_UUID, api);

    expect(api.users).not.toHaveBeenCalled();
    expect(api.roles).not.toHaveBeenCalled();
    expect(api.audit).not.toHaveBeenCalled();
    expect(api.auditEvent).not.toHaveBeenCalled();
    expect(store.error).toContain("audit.read");
  });

  it("setzt Read-Model-Fehler fail-closed und entfernt vorherige Daten", async () => {
    authorize();
    const api = fakeApi();
    const store = useAdministrationStore();
    await store.loadUsers(undefined, api);
    await store.loadAudit(undefined, api);
    vi.mocked(api.users).mockRejectedValueOnce(new Error("down"));
    vi.mocked(api.roles).mockRejectedValueOnce(new Error("down"));
    vi.mocked(api.audit).mockRejectedValueOnce(new Error("down"));
    vi.mocked(api.auditEvent).mockRejectedValueOnce(new Error("down"));

    await store.loadUsers(undefined, api);
    await store.loadRoles(api);
    await store.loadAudit(undefined, api);
    await store.selectAuditEvent(OTHER_UUID, api);

    expect(store.users).toEqual([]);
    expect(store.roles).toEqual([]);
    expect(store.auditEvents).toEqual([]);
    expect(store.selectedAuditEvent).toBeNull();
    expect(store.error).toContain("nicht sicher geladen");
  });

  it("führt alle Commands mit CSRF und derselben übergebenen Idempotenzquelle aus", async () => {
    authorize();
    const api = fakeApi();
    const store = useAdministrationStore();
    const key = () => "fixed-key";
    expect(
      await store.createUser(
        {
          expectedRevision: 0,
          username: "ada",
          displayName: "Ada",
          password: "correct horse battery staple",
          roles: ["viewer"],
        },
        api,
        key,
      ),
    ).toBe(true);
    expect(
      await store.updateUser(
        UUID,
        { expectedRevision: 1, displayName: "Ada" },
        api,
        key,
      ),
    ).toBe(true);
    expect(
      await store.disableUser(UUID, { expectedRevision: 2 }, api, key),
    ).toBe(true);
    expect(
      await store.replaceRoles(
        UUID,
        { expectedRevision: 2, roles: ["admin"] },
        api,
        key,
      ),
    ).toBe(true);

    expect(api.createUser).toHaveBeenCalledWith(expect.any(Object), {
      csrfToken: "csrf",
      idempotencyKey: "fixed-key",
    });
    expect(api.updateUser).toHaveBeenCalled();
    expect(api.disableUser).toHaveBeenCalled();
    expect(api.replaceRoles).toHaveBeenCalled();
    expect(store.success).toContain("gespeichert");
    expect(store.pending).toBe(false);
  });

  it.each([
    [{ httpStatus: 400 }, "ungültig", null, []],
    [{ httpStatus: 403 }, "Berechtigung", null, []],
    [
      { httpStatus: 409, payload: { error: { currentRevision: 9 } } },
      "zwischenzeitlich",
      9,
      [],
    ],
    [
      { httpStatus: 422, payload: { error: { blockers: ["self_lockout"] } } },
      "Sicherheitsregel",
      null,
      ["self_lockout"],
    ],
    [{ httpStatus: 503 }, "nicht verfügbar", null, []],
    [new Error("unknown"), "nicht angewendet", null, []],
  ])(
    "projiziert Command-Fehler ohne sensible Serverdetails",
    async (caught, message, revision, blockers) => {
      authorize();
      const api = fakeApi();
      vi.mocked(api.disableUser).mockRejectedValueOnce(caught);
      const store = useAdministrationStore();

      expect(
        await store.disableUser(
          UUID,
          { expectedRevision: 1 },
          api,
          () => "key",
        ),
      ).toBe(false);
      expect(store.error).toContain(message);
      expect(store.conflictRevision).toBe(revision);
      expect(store.blockers).toEqual(blockers);
      expect(store.pending).toBe(false);
    },
  );

  it("verweigert Mutationen ohne Permission oder CSRF vor dem API-Aufruf", async () => {
    authorize([]);
    const api = fakeApi();
    const store = useAdministrationStore();
    expect(await store.disableUser(UUID, { expectedRevision: 1 }, api)).toBe(
      false,
    );
    expect(api.disableUser).not.toHaveBeenCalled();
    expect(store.error).toContain("security.manage");
  });

  it("erzeugt standardmäßig einen kryptografischen Idempotenzschlüssel", async () => {
    authorize();
    const api = fakeApi();
    const randomUUID = vi
      .spyOn(globalThis.crypto, "randomUUID")
      .mockReturnValue("00000000-0000-4000-8000-000000000000");
    const store = useAdministrationStore();

    expect(await store.disableUser(UUID, { expectedRevision: 1 }, api)).toBe(
      true,
    );
    expect(randomUUID).toHaveBeenCalledOnce();
    expect(api.disableUser).toHaveBeenCalledWith(
      UUID,
      { expectedRevision: 1 },
      {
        csrfToken: "csrf",
        idempotencyKey: "00000000-0000-4000-8000-000000000000",
      },
    );
  });
});
