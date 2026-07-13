import { afterEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { createMemoryHistory, createRouter } from "vue-router";

import LoginView from "./LoginView.vue";

function authResponse(status = 200): Response {
  return new Response(
    JSON.stringify(
      status === 200
        ? {
            user: {
              id: "00000000-0000-0000-0000-000000000001",
              username: "viewer",
              permissions: ["inventory.read"],
            },
            csrfToken: "csrf",
            idleExpiresAt: "2026-07-12T10:30:00.000000Z",
            absoluteExpiresAt: "2026-07-12T22:00:00.000000Z",
          }
        : { error: { code: "authentication_failed" } },
    ),
    { status },
  );
}

describe("LoginView", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("meldet an und folgt nur einem lokalen Redirect", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(authResponse()));
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: "/login", component: LoginView },
        { path: "/inventory", component: { template: "<div>Inventory</div>" } },
      ],
    });
    await router.push("/login?redirect=/inventory");
    const wrapper = mount(LoginView, {
      global: { plugins: [createPinia(), router, PrimeVue] },
    });
    await wrapper.get("#username").setValue("viewer");
    await wrapper.get("input#password").setValue("secret-password");
    await wrapper.get("form").trigger("submit");
    await vi.waitFor(() =>
      expect(router.currentRoute.value.path).toBe("/inventory"),
    );
    expect(wrapper.text()).not.toContain("Anmeldung fehlgeschlagen");
  });

  it("zeigt einen generischen Fehler und leert das Passwort", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(authResponse(401)));
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: "/login", component: LoginView }],
    });
    await router.push("/login");
    const wrapper = mount(LoginView, {
      global: { plugins: [createPinia(), router, PrimeVue] },
    });
    await wrapper.get("#username").setValue("viewer");
    await wrapper.get("input#password").setValue("wrong-password");
    await wrapper.get("form").trigger("submit");
    await vi.waitFor(() =>
      expect(wrapper.text()).toContain("Anmeldung fehlgeschlagen"),
    );
    expect(
      (wrapper.get("input#password").element as HTMLInputElement).value,
    ).toBe("");
  });
});
