import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import QueueHistory from "./QueueHistory.vue";
import { loadQueueHistory } from "@/api/queueMetricsApi";
import type { QueueMetricHistory } from "@/api/generated/types.gen";
vi.mock("@/api/queueMetricsApi", () => ({ loadQueueHistory: vi.fn() }));
const load = vi.mocked(loadQueueHistory);
const stubs = {
  Button: {
    props: ["label", "disabled"],
    template: '<button :disabled="disabled">{{ label }}</button>',
  },
  Message: { template: "<div role=alert><slot /></div>" },
  Select: {
    props: ["modelValue"],
    emits: ["update:modelValue"],
    template:
      '<select :value="modelValue" @change="$emit(\'update:modelValue\', Number($event.target.value))"><option value="24">24 Stunden</option><option value="168">7 Tage</option><option value="720">30 Tage</option></select>',
  },
};
const result: QueueMetricHistory = {
  bucketSeconds: 120,
  retentionDays: 30,
  items: Array.from({ length: 25 }, (_, n) => ({
    observedAt: new Date(Date.UTC(2026, 8, 8, 12, n * 2)).toISOString(),
    samples: 1,
    waitingAverage: 2.5,
    waitingPeak: 4,
    activePeak: 1,
    unresolvedPeak: 0,
    oldestWaitSeconds: 120,
  })),
};
describe("Queue history", () => {
  beforeEach(() => vi.clearAllMocks());
  it("shows measurements, bounded pages and selectable aggregation", async () => {
    load.mockResolvedValue(result);
    const wrapper = mount(QueueHistory, { global: { stubs } });
    await flushPromises();
    expect(load).toHaveBeenCalledWith(24);
    expect(wrapper.findAll("tbody tr")).toHaveLength(24);
    expect(wrapper.text()).toContain("2,5 / 4");
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Ältere Intervalle")!
      .trigger("click");
    expect(wrapper.findAll("tbody tr")).toHaveLength(1);
    await wrapper.get("select").setValue("168");
    await flushPromises();
    expect(load).toHaveBeenLastCalledWith(168);
    expect(wrapper.text()).toContain("Seite 1 von 2");
  });
  it("distinguishes failures from empty history and supports retry", async () => {
    load
      .mockRejectedValueOnce(new Error("unavailable"))
      .mockResolvedValueOnce({ ...result, items: [] });
    const wrapper = mount(QueueHistory, { global: { stubs } });
    expect(wrapper.text()).toContain("Verlauf wird geladen");
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain(
      "erneut aktualisieren",
    );
    await wrapper.get("button").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain("Noch keine Messungen");
    expect(wrapper.find("table").exists()).toBe(false);
  });
  it("discards stale responses after switching period", async () => {
    let finish!: (value: QueueMetricHistory) => void;
    load
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            finish = resolve;
          }),
      )
      .mockResolvedValueOnce({ ...result, items: [] });
    const wrapper = mount(QueueHistory, { global: { stubs } });
    await wrapper.get("select").setValue("720");
    await flushPromises();
    finish(result);
    await flushPromises();
    expect(wrapper.text()).toContain("Noch keine Messungen");
    expect(wrapper.find("table").exists()).toBe(false);
  });
});
