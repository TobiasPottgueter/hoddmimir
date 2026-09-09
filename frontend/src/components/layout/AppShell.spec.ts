import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { useAuthStore } from "@/stores/auth";

import AppShell from "./AppShell.vue";

describe("AppShell", () => {
  it("zeigt den Displaynamen Hoddmímir als Produktmarke", async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.$patch({
      principal: {
        id: "00000000-0000-0000-0000-000000000001",
        username: "viewer",
        permissions: ["inventory.read"],
      },
      initialized: true,
    });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: "/",
          component: { template: "<div>Dashboard</div>" },
          meta: { title: "Übersicht" },
        },
        {
          path: "/:pathMatch(.*)*",
          component: { template: "<div />" },
        },
        {
          path: "/login",
          name: "login",
          component: { template: "<div>Login</div>" },
        },
      ],
    });

    await router.push("/");
    await router.isReady();

    const wrapper = mount(AppShell, {
      global: { plugins: [router, pinia] },
    });

    expect(wrapper.get(".app-brand strong").text()).toBe("Hoddmímir");
    expect(wrapper.get(".app-topbar__eyebrow").text()).toBe("Hoddmímir");
    expect(wrapper.get(".app-sidebar__footer").text()).toContain(
      "Automatische Zyklen · Read-only",
    );
    expect(wrapper.get(".app-topbar__principal").text()).toContain("viewer");
    expect(wrapper.get(".app-topbar__principal").text()).toContain("Abmelden");
    expect(
      wrapper.get('[aria-label="Shadow-Auswertungen"]').attributes("href"),
    ).toBe("/shadow");
    expect(wrapper.text()).not.toContain("Einrichtung ausstehend");
    expect(wrapper.text()).not.toContain("Noch keine Systeme verbunden");
    expect(wrapper.text()).not.toContain("Initialisierung");
    expect(wrapper.text()).not.toContain(
      ["Proxmox", "Backup", "Scheduler"].join(" "),
    );
    expect(wrapper.text()).not.toContain("Administration");

    await wrapper.get(".navigation-toggle").trigger("click");
    expect(wrapper.get(".app-sidebar").classes()).toContain(
      "app-sidebar--open",
    );
    await wrapper.get(".app-brand").trigger("click");
    expect(wrapper.get(".app-sidebar").classes()).not.toContain(
      "app-sidebar--open",
    );

    auth.principal!.permissions.push("security.manage");
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get('[aria-label="Administration"]').attributes("href"),
    ).toBe("/administration");

    const logout = vi.spyOn(auth, "logout").mockResolvedValue();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Abmelden"))!
      .trigger("click");
    await flushPromises();
    expect(logout).toHaveBeenCalledOnce();
    expect(router.currentRoute.value.name).toBe("login");
  });
});
