import { ref } from "vue";
import { afterEach, describe, expect, it, vi } from "vitest";

import { freshnessState, useFreshness } from "./useFreshness";

describe("freshnessState", () => {
  const now = Date.parse("2026-07-12T10:00:00Z");

  it.each([
    [null, "missing"],
    ["invalid", "invalid"],
    ["2026-07-12T10:02:00Z", "invalid"],
    ["2026-07-12T09:56:00Z", "fresh"],
    ["2026-07-12T09:54:59Z", "stale"],
  ] as const)("ordnet %s als %s ein", (timestamp, expected) => {
    expect(freshnessState(timestamp, now)).toBe(expected);
  });

  it("stellt den reaktiven Zustand und das stale-Flag bereit", () => {
    const timestamp = ref<string | null>("2026-07-12T09:00:00Z");
    const { state, stale } = useFreshness(timestamp, now);
    expect(state.value).toBe("stale");
    expect(stale.value).toBe(true);
    timestamp.value = "2026-07-12T10:00:00Z";
    expect(state.value).toBe("fresh");
    expect(stale.value).toBe(false);
  });

  it("verwendet ohne injizierte Uhr die aktuelle Systemzeit", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-12T10:00:00Z"));
    const { state } = useFreshness(() => "2026-07-12T10:00:00Z");
    expect(state.value).toBe("fresh");
  });

  afterEach(() => vi.useRealTimers());
});
