import { mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { describe, expect, it } from "vitest";

import AppShell from "./AppShell.vue";

describe("AppShell", () => {
  it("zeigt den Displaynamen Hoddmímir als Produktmarke", async () => {
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
      ],
    });

    await router.push("/");
    await router.isReady();

    const wrapper = mount(AppShell, {
      global: { plugins: [router] },
    });

    expect(wrapper.get(".app-brand strong").text()).toBe("Hoddmímir");
    expect(wrapper.get(".app-topbar__eyebrow").text()).toBe("Hoddmímir");
    expect(wrapper.get(".app-sidebar__footer").text()).toContain(
      "Automatische Zyklen · Read-only",
    );
    expect(wrapper.get(".app-topbar__environment").text()).toBe(
      "Betriebsansicht",
    );
    expect(wrapper.text()).not.toContain("Einrichtung ausstehend");
    expect(wrapper.text()).not.toContain("Noch keine Systeme verbunden");
    expect(wrapper.text()).not.toContain("Initialisierung");
    expect(wrapper.text()).not.toContain(
      ["Proxmox", "Backup", "Scheduler"].join(" "),
    );
  });
});
