import { afterEach, describe, expect, it } from "vitest";

import router from "./router";
import { pinia } from "./pinia";
import { useAuthStore } from "@/stores/auth";

describe("Browser-Titel", () => {
  afterEach(() => {
    document.title = "";
  });

  it("kombiniert den Seitentitel mit dem Displaynamen Hoddmímir", async () => {
    useAuthStore(pinia).$patch({
      initialized: true,
      principal: {
        id: "00000000-0000-0000-0000-000000000001",
        username: "viewer",
        permissions: ["inventory.read"],
      },
    });
    await router.push("/systems");
    await router.isReady();

    expect(document.title).toBe("Systeme · Hoddmímir");
  });

  it("registriert die geschützte Shadow-Ansicht mit eigenem Seitentitel", async () => {
    useAuthStore(pinia).$patch({
      initialized: true,
      principal: {
        id: "00000000-0000-0000-0000-000000000001",
        username: "viewer",
        permissions: ["inventory.read"],
      },
    });
    await router.push("/shadow");

    expect(router.currentRoute.value.name).toBe("shadow");
    expect(document.title).toBe("Shadow-Auswertungen · Hoddmímir");
  });

  it("leitet anonyme Zugriffe mit lokalem Rücksprungziel zum Login", async () => {
    const auth = useAuthStore(pinia);
    auth.clear();
    await router.push("/inventory");
    expect(router.currentRoute.value.name).toBe("login");
    expect(router.currentRoute.value.query.redirect).toBe("/inventory");
  });

  it("lädt jede geschützte Lazy-Route aus dem versionierten Navigationsvertrag", async () => {
    useAuthStore(pinia).$patch({
      initialized: true,
      principal: {
        id: "00000000-0000-0000-0000-000000000001",
        username: "admin",
        permissions: [
          "inventory.read",
          "backup_configuration.manage",
          "security.manage",
          "audit.read",
        ],
      },
    });
    for (const path of [
      "/inventory",
      "/operations",
      "/backup-targets",
      "/policies",
      "/queue",
      "/runs",
      "/connections",
      "/administration",
    ]) {
      await router.push(path);
      expect(router.currentRoute.value.fullPath).toBe(path);
    }
  });
});
