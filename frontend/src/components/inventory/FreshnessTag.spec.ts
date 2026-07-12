import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";

import FreshnessTag from "./FreshnessTag.vue";

describe("FreshnessTag", () => {
  const now = Date.parse("2026-07-12T10:00:00Z");

  it.each([
    ["2026-07-12T10:00:00Z", "Aktuell"],
    ["2026-07-12T09:00:00Z", "Veraltet"],
    [null, "Keine Messung"],
    ["ungültig", "Ungültig"],
  ] as const)("zeigt für %s den Zustand %s", (timestamp, label) => {
    const wrapper = mount(FreshnessTag, { props: { timestamp, now } });
    expect(wrapper.text()).toContain(label);
  });

  it("altert reaktiv und räumt die Uhr beim Unmount auf", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-12T10:00:00Z"));
    const clearInterval = vi.spyOn(window, "clearInterval");
    const wrapper = mount(FreshnessTag, {
      props: { timestamp: "2026-07-12T10:00:00Z" },
    });

    expect(wrapper.text()).toContain("Aktuell");
    vi.advanceTimersByTime(6 * 60 * 1000);
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Veraltet");

    wrapper.unmount();
    expect(clearInterval).toHaveBeenCalledOnce();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });
});
