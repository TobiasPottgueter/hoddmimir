import { mount, RouterLinkStub } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { describe, expect, it } from "vitest";

import DashboardView from "./DashboardView.vue";
import SectionPlaceholderView from "./SectionPlaceholderView.vue";

describe("statische Ansichten", () => {
  it("zeigt wahrheitsgemäße Read-only-Einstiege und die drei Komponenten", () => {
    const wrapper = mount(DashboardView, {
      global: {
        stubs: { RouterLink: RouterLinkStub },
      },
    });
    expect(wrapper.text()).toContain("Betriebsübersicht");
    expect(wrapper.text()).toContain("Read-only Einblick");
    expect(wrapper.text()).toContain("Collector Worker");
    expect(wrapper.text()).toContain("Backup Worker");
    expect(wrapper.text()).toContain("WebApp");
    expect(
      wrapper.findAllComponents(RouterLinkStub).map((link) => link.props("to")),
    ).toEqual(["/systems", "/inventory", "/operations"]);
    expect(wrapper.text()).not.toContain("0 von 3");
    expect(wrapper.text()).not.toContain("noch keine produktiven Daten");
    expect(wrapper.text()).not.toContain("Verbinde zuerst");
    expect(wrapper.text()).not.toContain("API-Zugängen verbinden");
  });

  it("liest Titel und Beschreibung des späteren Bereichs aus der Route", async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: "/later",
          component: SectionPlaceholderView,
          meta: { title: "Später", description: "Kommt später." },
        },
      ],
    });
    await router.push("/later");
    await router.isReady();
    const wrapper = mount(SectionPlaceholderView, {
      global: { plugins: [router] },
    });
    expect(wrapper.text()).toContain("Später");
    expect(wrapper.text()).toContain("Kommt später.");
  });

  it("verwendet sichere Fallbacktexte ohne Metadaten", async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: "/empty", component: SectionPlaceholderView }],
    });
    await router.push("/empty");
    await router.isReady();
    const wrapper = mount(SectionPlaceholderView, {
      global: { plugins: [router] },
    });
    expect(wrapper.text()).toContain("Bereich");
    expect(wrapper.text()).toContain("späteren Ausbaustufe");
  });
});
