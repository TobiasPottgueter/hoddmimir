import { mount, RouterLinkStub } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createPinia } from "pinia";

import DashboardView from "./DashboardView.vue";

describe("statische Ansichten", () => {
  it("zeigt wahrheitsgemäße Read-only-Einstiege und die drei Komponenten", () => {
    const wrapper = mount(DashboardView, {
      global: {
        plugins: [createPinia()],
        stubs: { RouterLink: RouterLinkStub },
      },
    });
    expect(wrapper.text()).toContain("Betriebsübersicht");
    expect(wrapper.text()).toContain("Hoddmímir auf einen Blick");
    expect(wrapper.text()).toContain("Collector");
    expect(wrapper.text()).toContain("Backup-Worker");
    expect(wrapper.text()).toContain("Scheduler-Evidenz");
    expect(wrapper.text()).not.toContain("0 von 3");
    expect(wrapper.text()).not.toContain("noch keine produktiven Daten");
    expect(wrapper.text()).not.toContain("Verbinde zuerst");
    expect(wrapper.text()).not.toContain("API-Zugängen verbinden");
  });
});
