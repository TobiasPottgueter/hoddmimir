import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";

import type { AuthApi, AuthSession } from "@/api/authApi";
import { useAuthStore } from "./auth";

const session: AuthSession = {
  user: {
    id: "00000000-0000-0000-0000-000000000001",
    username: "viewer",
    permissions: ["inventory.read"],
  },
  csrfToken: "memory-only",
  idleExpiresAt: "2026-07-12T10:30:00.000000Z",
  absoluteExpiresAt: "2026-07-12T22:00:00.000000Z",
};

function api(overrides: Partial<AuthApi> = {}): AuthApi {
  return {
    login: vi.fn().mockResolvedValue(session),
    session: vi.fn().mockResolvedValue(session),
    logout: vi.fn().mockResolvedValue(undefined),
    ...overrides,
  };
}

describe("auth store", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.stubGlobal("localStorage", {
      setItem: vi.fn(),
      getItem: vi.fn(),
      removeItem: vi.fn(),
    });
  });

  it("stellt die Cookie-Session einmalig wieder her und hält CSRF nur im Store", async () => {
    const store = useAuthStore();
    const client = api();
    await store.restore(client);
    await store.restore(client);
    expect(client.session).toHaveBeenCalledTimes(1);
    expect(store.authenticated).toBe(true);
    expect(store.csrfToken).toBe("memory-only");
    expect(store.hasPermission("inventory.read")).toBe(true);
    expect(store.hasPermission("audit.read")).toBe(false);
    expect(store.hasPermission("backup_configuration.manage")).toBe(false);
    expect(localStorage.setItem).not.toHaveBeenCalled();
    store.clear();
    expect(store.hasPermission("inventory.read")).toBe(false);
  });

  it("schließt bei Restore-Fehler und Logout fail-closed", async () => {
    const store = useAuthStore();
    await store.restore(
      api({ session: vi.fn().mockRejectedValue(new Error("401")) }),
    );
    expect(store.authenticated).toBe(false);
    store.$patch({
      principal: session.user,
      csrfToken: session.csrfToken,
      initialized: true,
    });
    const client = api({
      logout: vi.fn().mockRejectedValue(new Error("network")),
    });
    await expect(store.logout(client)).rejects.toThrow("network");
    expect(store.authenticated).toBe(false);
    expect(store.csrfToken).toBeNull();
  });

  it("übernimmt Login-Ergebnis", async () => {
    const store = useAuthStore();
    await store.login("viewer", "password", api());
    expect(store.principal?.username).toBe("viewer");
    store.clear();
    expect(store.principal).toBeNull();
    const client = api();
    await store.logout(client);
    expect(client.logout).not.toHaveBeenCalled();
  });

  it("verhindert parallele Restores und setzt Loading auch bei Loginfehler zurück", async () => {
    const store = useAuthStore();
    store.$patch({ loading: true });
    const client = api();
    await store.restore(client);
    expect(client.session).not.toHaveBeenCalled();
    store.$patch({ loading: false });
    await expect(
      store.login(
        "viewer",
        "wrong",
        api({ login: vi.fn().mockRejectedValue(new Error("401")) }),
      ),
    ).rejects.toThrow("401");
    expect(store.loading).toBe(false);
  });
});
