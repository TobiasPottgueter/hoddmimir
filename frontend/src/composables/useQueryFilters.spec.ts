import { defineComponent, h } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { describe, expect, it, vi } from "vitest";
import {
  queryChoice,
  queryText,
  queryUuid,
  useQueryFilters,
} from "./useQueryFilters";
describe("URL filters", () => {
  it("validates IDs, text lengths, enums and repeated parameters", () => {
    expect(
      queryChoice(
        { state: ["failed", "running"] },
        "state",
        ["all", "failed"],
        "all",
      ),
    ).toBe("all");
    expect(queryChoice({ state: "bad" }, "state", ["all"], "all")).toBe("all");
    expect(queryUuid({ id: "not-a-uuid" }, "id")).toBe("");
    expect(
      queryUuid({ id: "11111111-1111-4111-8111-111111111111" }, "id"),
    ).toBe("11111111-1111-4111-8111-111111111111");
    expect(queryText({ search: "x".repeat(201) }, "search")).toBe("");
    expect(queryText({ search: "  guest  " }, "search")).toBe("guest");
  });
  it("hydrates shared links, restores Back and resets applied filters", async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: "/runs", component: { render: () => null } }],
    });
    await router.push("/runs?state=failed");
    const read = vi.fn();
    const reload = vi.fn();
    let filters!: ReturnType<typeof useQueryFilters>;
    mount(
      defineComponent({
        setup() {
          filters = useQueryFilters(read, reload);
          return () => h("div");
        },
      }),
      { global: { plugins: [router] } },
    );
    expect(read).toHaveBeenLastCalledWith({ state: "failed" });
    expect(reload).not.toHaveBeenCalled();
    await filters.apply({ state: "running" });
    await flushPromises();
    expect(read).toHaveBeenLastCalledWith({ state: "running" });
    expect(reload).toHaveBeenCalledTimes(1);
    router.back();
    await flushPromises();
    expect(read).toHaveBeenLastCalledWith({ state: "failed" });
    await filters.apply({});
    await flushPromises();
    expect(router.currentRoute.value.query).toEqual({});
    await filters.apply({});
    expect(reload).toHaveBeenCalledTimes(4);
  });
});
