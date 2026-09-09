import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { overview, resource } from "@/test/fixtures";
import { useSystemsStore } from "@/stores/systems";
import SystemsView from "./SystemsView.vue";

describe("SystemsView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt PVE und PBS ausschließlich lesend", () => {
    const store = useSystemsStore();
    store.overview = overview;
    store.pveClusters = [resource("pve_cluster", { topology: "clustered" })];
    store.pbsServers = [resource("pbs_server", { version: "4.2" })];
    vi.spyOn(store, "load").mockResolvedValue();

    const wrapper = mount(SystemsView);

    expect(store.load).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain("PVE-Cluster");
    expect(wrapper.text()).toContain("Proxmox Backup Server");
    expect(wrapper.text()).toContain("Version: 4.2");
    expect(wrapper.text()).toContain("automatischen Collector-Zyklus");
    expect(wrapper.text()).not.toMatch(/jetzt scannen/i);
  });
});
