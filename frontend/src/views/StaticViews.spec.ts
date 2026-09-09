import { mount, RouterLinkStub } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createPinia } from "pinia";

import DashboardView from "./DashboardView.vue";

describe("statische Ansichten", () => {
  it("zeigt ohne Daten keine erfundenen Kennzahlen", () => {
    const wrapper = mount(DashboardView, {
      global: {
        plugins: [createPinia()],
        stubs: { RouterLink: RouterLinkStub },
      },
    });
    expect(wrapper.text()).toContain("Betriebsübersicht");
    expect(wrapper.text()).toContain("Hoddmímir auf einen Blick");
    expect(wrapper.text()).toContain("Collector");
    expect(wrapper.text()).toContain("Noch kein erfolgreicher Abruf");
    expect(wrapper.text()).not.toContain("0 von 3");
    expect(wrapper.text()).not.toContain("noch keine produktiven Daten");
    expect(wrapper.text()).not.toContain("Verbinde zuerst");
    expect(wrapper.text()).not.toContain("API-Zugängen verbinden");
  });
});
