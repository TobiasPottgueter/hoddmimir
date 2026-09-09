import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import Select from "primevue/select";
import { describe, expect, it, vi } from "vitest";
import PagedPicker from "./PagedPicker.vue";
const page = (id: string, nextCursor: string | null = null) => ({
  items: [{ id, label: `Guest ${id}` }],
  page: { count: 1, limit: 100, hasMore: nextCursor !== null, nextCursor },
});
describe("PagedPicker", () => {
  it("loads lazily, preserves a shared ID, and exposes additional pages", async () => {
    const loadPage = vi
      .fn()
      .mockResolvedValueOnce(page("a", "next"))
      .mockResolvedValueOnce(page("b"));
    const wrapper = mount(PagedPicker, {
      props: { modelValue: "saved", label: "Gast", loadPage },
      global: { plugins: [PrimeVue] },
    });
    expect(loadPage).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain("saved");
    wrapper.getComponent(Select).vm.$emit("show");
    await flushPromises();
    expect(wrapper.text()).toContain("Suche in 1 geladenen Einträgen");
    await wrapper.get("button").trigger("click");
    await flushPromises();
    expect(loadPage).toHaveBeenLastCalledWith("next");
    expect(wrapper.getComponent(Select).props("options")).toHaveLength(3);
    expect(wrapper.text()).not.toContain("Suche in");
  });
  it("discards old context responses and offers retry after errors", async () => {
    let resolve!: (value: ReturnType<typeof page>) => void;
    const loadPage = vi.fn(
      () =>
        new Promise<ReturnType<typeof page>>((done) => {
          resolve = done;
        }),
    );
    const wrapper = mount(PagedPicker, {
      props: { modelValue: "", label: "Cluster", loadPage },
      global: { plugins: [PrimeVue] },
    });
    wrapper.getComponent(Select).vm.$emit("show");
    await flushPromises();
    const next = vi
      .fn()
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValue(page("new"));
    await wrapper.setProps({ loadPage: next });
    resolve(page("old"));
    await flushPromises();
    expect(wrapper.getComponent(Select).props("options")).toEqual([]);
    wrapper.getComponent(Select).vm.$emit("show");
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    await wrapper.get("button").trigger("click");
    await flushPromises();
    expect(wrapper.getComponent(Select).props("options")).toEqual(
      page("new").items,
    );
  });
});
