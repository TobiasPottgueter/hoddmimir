import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import type { OperationsApi } from "@/api/operationsApi";
import { useAuthStore } from "./auth";
import { useBackupOperationsStore } from "./backupOperations";

const id = "11111111-1111-1111-1111-111111111111";
const metadata = (more = false) => ({
  limit: 25,
  count: 1,
  hasMore: more,
  nextCursor: more ? "next" : null,
});
const request = {
  id,
  rootRequestId: id,
  runId: null,
  state: "pending" as const,
  origin: "manual" as const,
  reason: "manual" as const,
  priority: 400,
  attempt: 1,
  scheduledAt: "2026-07-13T00:00:00.000000Z",
  availableAt: "2026-07-13T00:00:00.000000Z",
  createdAt: "2026-07-13T00:00:00.000000Z",
  updatedAt: "2026-07-13T00:00:00.000000Z",
  cancelRequestedAt: null,
  terminalCode: null,
  revision: 1,
  guestId: id,
  guestName: "vm",
  guestType: "qemu" as const,
  vmid: 100,
  nodeName: "pve-a",
  policyName: "Daily",
  targetName: "PBS",
};
const run = {
  id,
  requestId: id,
  rootRequestId: id,
  guestId: id,
  state: "running" as const,
  attempt: 1,
  revision: 1,
  submissionProvenance: "accepted",
  upid: "UPID:safe",
  guestName: "vm",
  guestType: "qemu" as const,
  vmid: 100,
  nodeName: "pve-a",
  policyName: "Daily",
  targetName: "PBS",
  startedAt: "2026-07-13T00:00:00.000000Z",
  finishedAt: null,
};
const event = {
  id,
  sequence: 1,
  type: "started",
  state: "running",
  occurredAt: "2026-07-13T00:00:00.000000Z",
};
const line = {
  lineNo: 1,
  observedAt: "2026-07-13T00:00:00.000000Z",
  content: "safe",
};
function api(): OperationsApi {
  return {
    dashboard: vi.fn().mockResolvedValue({
      workers: {},
      collectorSchedule: null,
      resources: {},
      requestsByState: {},
      runsByState: {},
      staleEvidence: 0,
      shadowBlockers: 0,
      notifications: {},
      recentAuditEvents: [],
      auditVisible: false,
    }),
    queue: vi.fn().mockResolvedValue({ items: [request], page: metadata() }),
    runs: vi.fn().mockResolvedValue({ items: [run], page: metadata() }),
    run: vi.fn().mockResolvedValue(run),
    requestEvents: vi
      .fn()
      .mockResolvedValue({ items: [event], page: metadata(true) }),
    runEvents: vi
      .fn()
      .mockResolvedValue({ items: [event], page: metadata(true) }),
    logs: vi.fn().mockResolvedValue({ items: [line], page: metadata(true) }),
    notifications: vi.fn().mockResolvedValue({ items: [], page: metadata() }),
    notificationHealth: vi.fn().mockResolvedValue({ byState: { pending: 0 } }),
    manual: vi
      .fn()
      .mockResolvedValue({ status: "applied", requestId: id, revision: 1 }),
    cancel: vi
      .fn()
      .mockResolvedValue({ status: "applied", requestId: id, revision: 2 }),
  };
}
describe("backup operations store", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.stubGlobal("crypto", { randomUUID: vi.fn().mockReturnValue(id) });
  });
  it("loads, appends and projects all overview lists", async () => {
    const client = api();
    const store = useBackupOperationsStore();
    await store.loadDashboard(client);
    await store.loadQueue(client);
    await store.loadQueue(client, true);
    await store.loadRuns(client);
    await store.loadRuns(client, true);
    await store.loadNotifications(client);
    await store.loadNotifications(client, true);
    expect(store.queue).toHaveLength(2);
    expect(store.runs).toHaveLength(2);
    expect(store.notificationHealth).not.toBeNull();
    expect(store.emptyQueue).toBe(false);
    expect(client.queue).toHaveBeenLastCalledWith(25, undefined, undefined);
    expect(client.runs).toHaveBeenLastCalledWith(25, undefined, undefined);
    expect(client.notifications).toHaveBeenLastCalledWith(
      25,
      undefined,
      undefined,
    );

    store.queueState = "retry_wait";
    store.runState = "failed";
    store.notificationKind = "attention_required";
    await store.loadQueue(client);
    await store.loadRuns(client);
    await store.loadNotifications(client);
    expect(client.queue).toHaveBeenLastCalledWith(25, undefined, "retry_wait");
    expect(client.runs).toHaveBeenLastCalledWith(25, undefined, "failed");
    expect(client.notifications).toHaveBeenLastCalledWith(
      25,
      undefined,
      "attention_required",
    );
  });
  it("loads and paginates request events, run events and logs", async () => {
    const client = api();
    const store = useBackupOperationsStore();
    await store.loadRun(id, client);
    await store.loadMoreRequestEvents(client);
    await store.loadMoreRunEvents(client);
    await store.loadMoreLogs(client);
    expect(store.requestEvents).toHaveLength(2);
    expect(store.events).toHaveLength(2);
    expect(store.logs).toHaveLength(2);
    store.requestEventPage.hasMore = false;
    store.eventPage.hasMore = false;
    store.logPage.hasMore = false;
    await store.loadMoreRequestEvents(client);
    await store.loadMoreRunEvents(client);
    await store.loadMoreLogs(client);
  });
  it("guards load-more before a detail exists and while logs are loading", async () => {
    const client = api();
    const store = useBackupOperationsStore();
    await store.loadMoreRequestEvents(client);
    await store.loadMoreRunEvents(client);
    await store.loadMoreLogs(client);
    store.detail = run;
    store.logPage = metadata(true);
    store.loading = true;
    await store.loadMoreLogs(client);
    expect(client.logs).not.toHaveBeenCalled();
  });
  it("supports first continuation pages without a cursor defensively", async () => {
    const client = api();
    const store = useBackupOperationsStore();
    store.detail = run;
    store.requestEventPage = { ...metadata(true), nextCursor: null };
    store.eventPage = { ...metadata(true), nextCursor: null };
    store.logPage = { ...metadata(true), nextCursor: null };
    await store.loadMoreRequestEvents(client);
    await store.loadMoreRunEvents(client);
    await store.loadMoreLogs(client);
    expect(client.requestEvents).toHaveBeenCalledWith(id, 25, undefined);
    expect(client.runEvents).toHaveBeenCalledWith(id, 25, undefined);
    expect(client.logs).toHaveBeenCalledWith(id, 25, undefined);
  });
  it("applies manual and cancel commands with memory-only csrf", async () => {
    const client = api();
    const auth = useAuthStore();
    auth.$patch({ csrfToken: "csrf" });
    const store = useBackupOperationsStore();
    expect(
      await store.manual(
        { guestId: id, policyId: id, expectedRevision: 1 },
        client,
      ),
    ).toBe(true);
    expect(await store.cancel(request, client)).toBe(true);
    auth.$patch({ csrfToken: null });
    expect(
      await store.manual(
        { guestId: id, policyId: id, expectedRevision: 1 },
        client,
      ),
    ).toBe(false);
    expect(await store.cancel(request, client)).toBe(false);
  });
  it.each([
    [409, "Revision"],
    [422, "blockiert"],
    [503, "unavailable"],
  ] as const)("maps command HTTP status %s", async (status, text) => {
    const client = api();
    client.cancel = vi
      .fn()
      .mockRejectedValue(
        Object.assign(new Error("unavailable"), { httpStatus: status }),
      );
    useAuthStore().$patch({ csrfToken: "csrf" });
    const store = useBackupOperationsStore();
    expect(await store.cancel(request, client)).toBe(false);
    expect(store.error).toContain(text);
  });
  it("maps primitive command failures without leaking", async () => {
    const client = api();
    client.manual = vi.fn().mockRejectedValue("failed");
    useAuthStore().$patch({ csrfToken: "csrf" });
    const store = useBackupOperationsStore();
    expect(
      await store.manual(
        { guestId: id, policyId: id, expectedRevision: 1 },
        client,
      ),
    ).toBe(false);
    expect(store.error).toBeTruthy();
  });
  it("fails read requests closed and preserves an empty state", async () => {
    const client = api();
    client.queue = vi.fn().mockRejectedValue(new Error("unavailable"));
    const store = useBackupOperationsStore();
    await store.loadQueue(client);
    expect(store.error).toBe("unavailable");
    expect(store.loading).toBe(false);
    expect(store.emptyQueue).toBe(true);
  });
});
