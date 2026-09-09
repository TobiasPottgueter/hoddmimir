import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { DISPLAY_REFRESH_MS, useAutoRefresh } from "./useAutoRefresh";

describe("Automatische Anzeigeaktualisierung", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });
  it("wartet auf langsame Abrufe, pausiert im Hintergrund und räumt beim Verlassen auf", async () => {
    const visibility = vi
      .spyOn(document, "visibilityState", "get")
      .mockReturnValue("visible");
    let complete!: () => void;
    const load = vi
      .fn()
      .mockImplementationOnce(
        () =>
          new Promise<void>((resolve) => {
            complete = resolve;
          }),
      )
      .mockResolvedValue(undefined);
    let refresh!: ReturnType<typeof useAutoRefresh>;
    const wrapper = mount({
      setup() {
        refresh = useAutoRefresh(load);
        return {};
      },
      template: "<div />",
    });
    expect(load).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS * 3);
    await refresh.refresh();
    expect(load).toHaveBeenCalledTimes(1);
    complete();
    await flushPromises();
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS);
    expect(load).toHaveBeenCalledTimes(2);
    visibility.mockReturnValue("hidden");
    document.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS * 3);
    expect(load).toHaveBeenCalledTimes(2);
    visibility.mockReturnValue("visible");
    document.dispatchEvent(new Event("visibilitychange"));
    await flushPromises();
    expect(load).toHaveBeenCalledTimes(3);
    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS * 3);
    expect(load).toHaveBeenCalledTimes(3);
  });
  it("bewahrt geöffnete Folgeseiten, erlaubt bewusstes Neuladen und erholt sich nach Fehlern", async () => {
    const allowed = ref(false);
    const busy = ref(false);
    const load = vi
      .fn()
      .mockRejectedValueOnce(new Error("Verbindung unterbrochen"))
      .mockResolvedValue(undefined);
    let refresh!: ReturnType<typeof useAutoRefresh>;
    const wrapper = mount({
      setup() {
        refresh = useAutoRefresh(
          load,
          () => allowed.value,
          () => busy.value,
        );
        return {};
      },
      template: "<div />",
    });
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS);
    expect(load).toHaveBeenCalledTimes(1);
    expect(refresh.error.value).toContain("unterbrochen");
    await refresh.refresh();
    expect(load).toHaveBeenCalledTimes(2);
    allowed.value = true;
    busy.value = true;
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS);
    expect(load).toHaveBeenCalledTimes(2);
    busy.value = false;
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS);
    expect(load).toHaveBeenCalledTimes(3);
    expect(refresh.error.value).toBeNull();
    wrapper.unmount();
  });
  it("plant nach einer ausstehenden Antwort auf eine verlassene Seite keinen Timer", async () => {
    let complete!: () => void;
    const load = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          complete = resolve;
        }),
    );
    const wrapper = mount({
      setup() {
        useAutoRefresh(load);
      },
      template: "<div />",
    });
    wrapper.unmount();
    complete();
    await flushPromises();
    await vi.advanceTimersByTimeAsync(DISPLAY_REFRESH_MS * 2);
    expect(load).toHaveBeenCalledTimes(1);
  });
});
