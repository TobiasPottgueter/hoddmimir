import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import { useUnsavedChanges } from "./useUnsavedChanges";

describe("Ungespeicherte Änderungen", () => {
  afterEach(() => vi.restoreAllMocks());
  it("schützt Route und Browser-Reload, ohne unveränderte Formulare zu blockieren", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const state = ref("initial");
    const pending = ref(false);
    let guard!: ReturnType<typeof useUnsavedChanges>;
    const form = {
      setup() {
        guard = useUnsavedChanges(state, () => pending.value);
      },
      template: "<div>Formular</div>",
    };
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: "/", component: form },
        { path: "/next", component: { template: "<div>Weiter</div>" } },
      ],
    });
    await router.push("/");
    const wrapper = mount(
      { template: "<router-view />" },
      { global: { plugins: [router] } },
    );
    await flushPromises();
    expect(guard.confirmDiscard()).toBe(true);
    expect(confirm).not.toHaveBeenCalled();
    const cleanReload = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(cleanReload);
    expect(cleanReload.defaultPrevented).toBe(false);
    state.value = "changed";
    const dirtyReload = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(dirtyReload);
    expect(dirtyReload.defaultPrevented).toBe(true);
    await router.push("/next");
    expect(router.currentRoute.value.path).toBe("/");
    guard.reset();
    expect(guard.dirty.value).toBe(false);
    pending.value = true;
    expect(guard.confirmDiscard()).toBe(false);
    const pendingReload = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(pendingReload);
    expect(pendingReload.defaultPrevented).toBe(true);
    pending.value = false;
    state.value = "changed again";
    confirm.mockReturnValue(true);
    await router.push("/next");
    expect(router.currentRoute.value.path).toBe("/next");
    wrapper.unmount();
    const after = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(after);
    expect(after.defaultPrevented).toBe(false);
  });
});
