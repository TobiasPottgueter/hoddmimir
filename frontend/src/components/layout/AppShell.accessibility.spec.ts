import { flushPromises, mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useAuthStore } from "@/stores/auth";
import AppShell from "./AppShell.vue";

describe("Navigation mit Tastatur", () => {
  afterEach(() => vi.restoreAllMocks());

  it("isoliert das mobile Menü, schließt mit Escape und fokussiert neue Seiten", async () => {
    const media = {
      matches: true,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    };
    vi.spyOn(window, "matchMedia").mockReturnValue(
      media as unknown as MediaQueryList,
    );
    const pinia = createPinia();
    useAuthStore(pinia).$patch({
      initialized: true,
      principal: {
        id: "test",
        username: "viewer",
        permissions: ["inventory.read"],
      },
    });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: "/:pathMatch(.*)*",
          component: { template: "<h2>Inhalt</h2>" },
        },
      ],
    });
    await router.push("/");
    const wrapper = mount(AppShell, {
      attachTo: document.body,
      global: { plugins: [pinia, router] },
    });
    expect(wrapper.get("aside").attributes("inert")).toBeDefined();
    await wrapper.get(".navigation-toggle").trigger("click");
    await flushPromises();
    const close = wrapper.get<HTMLButtonElement>(".navigation-close");
    expect(document.activeElement).toBe(close.element);
    expect(wrapper.get(".app-workspace").attributes("inert")).toBeDefined();
    expect(wrapper.get("aside").attributes("aria-modal")).toBe("true");
    await close.trigger("keydown", { key: "Tab", shiftKey: true });
    const lastLink = wrapper.findAll("nav a").at(-1)!;
    expect(document.activeElement).toBe(lastLink.element);
    await lastLink.trigger("keydown", { key: "Tab" });
    expect(document.activeElement).toBe(close.element);
    await close.trigger("keydown", { key: "Escape" });
    expect(document.activeElement).toBe(
      wrapper.get(".navigation-toggle").element,
    );
    expect(wrapper.get("aside").attributes("inert")).toBeDefined();
    expect(document.body.style.overflow).not.toBe("hidden");

    await wrapper.get(".navigation-toggle").trigger("click");
    await wrapper.get('a[href="/runs"]').trigger("click");
    await flushPromises();
    expect(document.activeElement).toBe(wrapper.get("main").element);
    expect(wrapper.get(".app-workspace").attributes("inert")).toBeUndefined();

    media.matches = false;
    media.addEventListener.mock.calls[0]![1]();
    await flushPromises();
    expect(wrapper.get("aside").attributes("inert")).toBeUndefined();
    expect(wrapper.find(".navigation-close").exists()).toBe(false);
    wrapper.unmount();
    expect(media.removeEventListener).toHaveBeenCalled();
  });
});
