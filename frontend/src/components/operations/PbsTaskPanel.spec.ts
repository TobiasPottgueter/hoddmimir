import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PbsTaskPanel from "./PbsTaskPanel.vue";
import { loadPbsTask, loadPbsTasks } from "@/api/pbsTasksApi";

vi.mock("@/api/pbsTasksApi", () => ({
  loadPbsTasks: vi.fn(),
  loadPbsTask: vi.fn(),
}));
const task = {
  id: "00000000-0000-0000-0000-000000000001",
  connectionId: "00000000-0000-0000-0000-000000000002",
  workerType: "verify",
  workerId: "store",
  lifecycle: "stopped" as const,
  remoteStatus: "ok",
  startedAt: "2026-09-08T10:00:00Z",
  lastSeenAt: "2026-09-08T10:02:00Z",
  inspectedAt: "2026-09-08T10:02:00Z",
};
const inspection = {
  status: "stopped" as const,
  exitStatus: "OK",
  endTime: null,
  lines: [{ number: 1, text: "<script>bad()</script> password=[REDACTED]" }],
  truncated: true,
  statusFailure: null,
  logFailure: null,
};
function render() {
  return mount(PbsTaskPanel, { global: { plugins: [PrimeVue] } });
}
beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(loadPbsTasks).mockResolvedValue({ items: [task], total: 1 });
  vi.mocked(loadPbsTask).mockResolvedValue({
    ...task,
    upid: "UPID:fixture",
    inspection,
  });
});

describe("PBS task diagnostics", () => {
  it("loads stored details, labels truncation and renders logs as text", async () => {
    const wrapper = render();
    await flushPromises();
    const button = wrapper
      .findAll("button")
      .find((button) => button.text() === "Details und Log");
    await button!.trigger("click");
    await flushPromises();
    expect(loadPbsTask).toHaveBeenCalledWith(task.id);
    expect(wrapper.text()).toContain("Gekürzt");
    expect(wrapper.find("pre").text()).toContain("<script>bad()</script>");
    expect(wrapper.find("script").exists()).toBe(false);
    expect(wrapper.text()).toContain("password=[REDACTED]");
  });
  it("separates missing inspections from denied logs", async () => {
    vi.mocked(loadPbsTask).mockResolvedValueOnce({
      ...task,
      upid: "UPID:fixture",
      inspection: null,
    });
    const wrapper = render();
    await flushPromises();
    const click = async () => {
      await wrapper
        .findAll("button")
        .find((button) => button.text() === "Details und Log")!
        .trigger("click");
      await flushPromises();
    };
    await click();
    expect(wrapper.text()).toContain("noch keine Details");
    vi.mocked(loadPbsTask).mockResolvedValueOnce({
      ...task,
      upid: "UPID:fixture",
      inspection: {
        ...inspection,
        lines: [],
        truncated: false,
        logFailure: "permission_denied",
        statusFailure: "permission_denied",
      },
    });
    await click();
    expect(wrapper.text()).toContain("Log nicht verfügbar");
    expect(wrapper.text()).toContain("Status nicht verfügbar");
    expect(wrapper.find("pre").exists()).toBe(false);
  });
  it("filters and pages without remote scan actions", async () => {
    vi.mocked(loadPbsTasks).mockResolvedValue({ items: [task], total: 80 });
    const wrapper = render();
    await flushPromises();
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Weiter")!
      .trigger("click");
    await flushPromises();
    expect(loadPbsTasks).toHaveBeenLastCalledWith(50, undefined);
    await wrapper.find("input").setValue(task.connectionId);
    await wrapper.find("form").trigger("submit");
    await flushPromises();
    expect(loadPbsTasks).toHaveBeenLastCalledWith(0, task.connectionId);
    expect(wrapper.text()).not.toContain("Scan now");
  });
  it("shows read failures and empty data", async () => {
    vi.mocked(loadPbsTasks).mockRejectedValueOnce(new Error("offline"));
    const wrapper = render();
    await flushPromises();
    expect(wrapper.text()).toContain("konnten nicht geladen");
    vi.mocked(loadPbsTasks).mockResolvedValueOnce({ items: [], total: 0 });
    await wrapper.find("form").trigger("submit");
    await flushPromises();
    expect(wrapper.text()).toContain("Keine PBS-Tasks erfasst");
  });
});
