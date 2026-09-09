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
  policyId: id,
  targetId: id,
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
    runs: vi.fn().mockResolvedValue({ items: [run], page: metadata(true) }),
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
    expect(store.queue[0]).toMatchObject({
      guestId: id,
      policyId: id,
      targetId: id,
    });
    expect(store.runs).toHaveLength(2);
    expect(store.notificationHealth).not.toBeNull();
    expect(store.emptyQueue).toBe(false);
    expect(client.queue).toHaveBeenLastCalledWith(25, undefined, undefined);
    expect(client.runs).toHaveBeenLastCalledWith(25, "next", undefined, {});
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
    expect(client.runs).toHaveBeenLastCalledWith(25, undefined, "failed", {});
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
    store.detailStatus.loading = true;
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
    expect(store.mutationError).toContain(text);
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
    expect(store.mutationError).toBeTruthy();
  });
  it("distinguishes a failed read from a successful empty result", async () => {
    const client = api();
    client.queue = vi.fn().mockRejectedValue(new Error("unavailable"));
    const store = useBackupOperationsStore();
    await store.loadQueue(client);
    expect(store.queueStatus.error).toBe("unavailable");
    expect(store.queueStatus.loading).toBe(false);
    expect(store.emptyQueue).toBe(false);
  });
  it("isolates slow reads, preserves previous data on failure and rejects obsolete responses", async () => {
    const client = api();
    const store = useBackupOperationsStore();
    let finish!: (value: Awaited<ReturnType<OperationsApi["runs"]>>) => void;
    client.runs = vi.fn().mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const slow = store.loadRuns(client);
    client.notifications = vi
      .fn()
      .mockRejectedValue(new Error("Meldungen nicht erreichbar"));
    await store.loadNotifications(client);
    expect(store.runsStatus.loading).toBe(true);
    expect(store.runsStatus.error).toBeNull();
    expect(store.notificationStatus.error).toContain("Meldungen");
    client.runs = vi.fn().mockResolvedValue({ items: [run], page: metadata() });
    await store.loadRuns(client);
    finish({ items: [], page: metadata() });
    await slow;
    expect(store.runs).toEqual([run]);
    expect(store.runsStatus.loaded).toBe(true);
    const previousUpdate = store.runsStatus.updatedAt;
    client.runs = vi
      .fn()
      .mockRejectedValue(new Error("Läufe nicht erreichbar"));
    await store.loadRuns(client);
    expect(store.runs).toEqual([run]);
    expect(store.runsStatus.updatedAt).toBe(previousUpdate);
    expect(store.notificationStatus.error).toContain("Meldungen");
    client.queue = vi.fn().mockResolvedValue({ items: [], page: metadata() });
    await store.loadQueue(client);
    expect(store.emptyQueue).toBe(true);
  });
  it("does not submit a second command while one is pending", async () => {
    useAuthStore().$patch({ csrfToken: "test" });
    const client = api();
    let finish!: (value: unknown) => void;
    client.cancel = vi.fn().mockImplementation(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const store = useBackupOperationsStore();
    const first = store.cancel(request, client);
    expect(await store.cancel(request, client)).toBe(false);
    expect(client.cancel).toHaveBeenCalledTimes(1);
    finish({ status: "applied" });
    expect(await first).toBe(true);
    expect(store.mutationSuccess).toContain("Abbruch angefordert");
  });
  it("keeps filters on every page and discards old pages when changing the search", async () => {
    const store = useBackupOperationsStore();
    const client = api();
    const filters = { vmid: 100, nodeId: id, search: "vm" };
    store.setRunFilters("running", filters);
    client.runs = vi
      .fn()
      .mockResolvedValue({ items: [run], page: metadata(true) });
    await store.loadRuns(client);
    expect(client.runs).toHaveBeenLastCalledWith(
      25,
      undefined,
      "running",
      filters,
    );
    await store.loadRuns(client, true);
    expect(client.runs).toHaveBeenLastCalledWith(
      25,
      "next",
      "running",
      filters,
    );
    store.setRunFilters("running", filters);
    expect(store.runs).toHaveLength(2);
    let finish!: (value: Awaited<ReturnType<OperationsApi["runs"]>>) => void;
    client.runs = vi.fn(
      () =>
        new Promise<Awaited<ReturnType<OperationsApi["runs"]>>>((resolve) => {
          finish = resolve;
        }),
    );
    const old = store.loadRuns(client);
    store.setRunFilters("failed", { search: "new" });
    expect(store.runs).toEqual([]);
    expect(store.runsPage.nextCursor).toBeNull();
    finish({ items: [run], page: metadata(true) });
    await old;
    expect(store.runs).toEqual([]);
    expect(store.runsStatus.loaded).toBe(false);
    client.runs = vi
      .fn()
      .mockResolvedValue({ items: [], page: { ...metadata(), count: 0 } });
    await store.loadRuns(client);
    await store.loadRuns(client, true);
    expect(client.runs).toHaveBeenCalledOnce();
    expect(client.runs).toHaveBeenCalledWith(25, undefined, "failed", {
      search: "new",
    });
  });
  it("ignores late failures and blocks overlapping pagination", async () => {
    const store = useBackupOperationsStore();
    const client = api();
    let reject!: (reason: Error) => void;
    client.runs = vi.fn(
      () =>
        new Promise<Awaited<ReturnType<OperationsApi["runs"]>>>(
          (_resolve, fail) => {
            reject = fail;
          },
        ),
    );
    const old = store.loadRuns(client);
    await store.loadRuns(client, true);
    expect(client.runs).toHaveBeenCalledOnce();
    store.setRunFilters("failed", {});
    reject(new Error("old failure"));
    await old;
    expect(store.runsStatus.error).toBeNull();
    for (const section of ["queue", "notifications"] as const) {
      let resolve!: (value: {
        items: [];
        page: ReturnType<typeof metadata>;
      }) => void;
      client[section] = vi.fn(
        () =>
          new Promise<{ items: []; page: ReturnType<typeof metadata> }>(
            (done) => {
              resolve = done;
            },
          ),
      );
      const load =
        section === "queue" ? store.loadQueue : store.loadNotifications;
      const pending = load(client);
      await load(client, true);
      expect(client[section]).toHaveBeenCalledOnce();
      resolve({ items: [], page: metadata() });
      await pending;
    }
    await store.loadRun(id, client);
    await store.loadRun(id, client);
    expect(store.detail?.id).toBe(id);
    store.runsPage = { ...metadata(true), nextCursor: null };
    client.runs = vi
      .fn()
      .mockResolvedValue({ items: [run], page: metadata(true) });
    await store.loadRuns(client, true);
    expect(client.runs).toHaveBeenCalledWith(25, undefined, "failed", {});
  });
});
