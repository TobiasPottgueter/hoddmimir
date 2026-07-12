import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import Select from "primevue/select";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { UUID, resource } from "@/test/fixtures";
import { useInventoryStore } from "@/stores/inventory";
import InventoryView from "./InventoryView.vue";

describe("InventoryView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt Inventar, Filter und stabile Pagination ohne Scan-Aktion", async () => {
    const store = useInventoryStore();
    store.items = [resource("pve_guest", { guestType: "lxc", vmid: 101 })];
    store.page = {
      limit: 25,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    const load = vi.spyOn(store, "load").mockResolvedValue();
    const loadMore = vi.spyOn(store, "loadMore").mockResolvedValue();
    const wrapper = mount(InventoryView, { global: { plugins: [PrimeVue] } });

    expect(load).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain("VMs und Container");
    expect(wrapper.text()).toContain("LXC 101");
    expect(wrapper.text()).not.toMatch(/jetzt scannen/i);
    await wrapper.get(".table-pagination button").trigger("click");
    expect(loadMore).toHaveBeenCalledOnce();

    const selects = wrapper.findAllComponents(Select);
    expect(selects.length).toBeGreaterThanOrEqual(3);
    selects[2]?.vm.$emit("update:modelValue", "qemu");
    await wrapper.vm.$nextTick();
    expect(store.guestType).toBe("qemu");

    selects[0]?.vm.$emit("update:modelValue", "pve_storage");
    await wrapper.vm.$nextTick();
    expect(store.kind).toBe("pve_storage");

    selects[1]?.vm.$emit("update:modelValue", "archived");
    await wrapper.vm.$nextTick();
    expect(store.inventoryState).toBe("archived");

    store.connectionId = ` ${UUID} `;
    await wrapper.get("form").trigger("submit");
    expect(store.connectionId).toBe(UUID);

    expect(load.mock.calls.length).toBeGreaterThanOrEqual(4);
  });
});
