import { RouterLinkStub, mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import SetupStatusCard from "./SetupStatusCard.vue";

const defaultProps = {
  title: "Proxmox-Systeme",
  description: "PVE- und PBS-Endpunkte verbinden.",
  icon: "pi pi-server",
  actionLabel: "Systeme öffnen",
  actionTo: "/systems",
};

describe("SetupStatusCard", () => {
  it("kennzeichnet einen offenen Einrichtungsschritt und verlinkt das Ziel", () => {
    const wrapper = mount(SetupStatusCard, {
      props: defaultProps,
      global: {
        stubs: {
          RouterLink: RouterLinkStub,
        },
      },
    });

    expect(wrapper.text()).toContain("Offen");
    expect(wrapper.text()).toContain(defaultProps.description);
    expect(wrapper.getComponent(RouterLinkStub).props("to")).toBe("/systems");
  });

  it("zeigt einen abgeschlossenen Einrichtungsschritt eindeutig an", () => {
    const wrapper = mount(SetupStatusCard, {
      props: {
        ...defaultProps,
        configured: true,
      },
      global: {
        stubs: {
          RouterLink: RouterLinkStub,
        },
      },
    });

    expect(wrapper.text()).toContain("Eingerichtet");
    expect(wrapper.text()).not.toContain("Offen");
  });
});
