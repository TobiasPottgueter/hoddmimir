import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import QueueView from "./QueueView.vue";
import RunsView from "./RunsView.vue";
import RunDetailView from "./RunDetailView.vue";
import DashboardView from "./DashboardView.vue";
import { useAuthStore } from "@/stores/auth";
import { useBackupOperationsStore } from "@/stores/backupOperations";
import { usePoliciesStore } from "@/stores/policies";
import { useConfigurationInventoryStore } from "@/stores/configurationInventory";

const id = "11111111-1111-1111-1111-111111111111";
const baseRequest = {
  id,
  rootRequestId: id,
  runId: null,
  state: "pending" as const,
  origin: "manual" as const,
  reason: "manual" as const,
  priority: 400,
  attempt: 1,
  revision: 1,
  guestId: id,
  guestName: "vm",
  guestType: "qemu" as const,
  vmid: 100,
  nodeName: "pve-a",
  policyName: "Daily",
  targetName: "PBS",
  scheduledAt: "2026-07-13T00:00:00.000000Z",
  availableAt: "2026-07-13T00:00:00.000000Z",
  createdAt: "2026-07-13T00:00:00.000000Z",
  updatedAt: "2026-07-13T00:00:00.000000Z",
  cancelRequestedAt: null,
  terminalCode: null,
};
const baseRun = {
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
const baseNotification = {
  id,
  kind: "recovery" as const,
  state: "sent" as const,
  attempt: 1,
  deliveryAttempts: 1,
  guestName: "vm",
  guestType: "qemu" as const,
  vmid: 100,
  node: "pve-a",
  targetLabel: "PBS",
  problemCode: "task_failed",
  detailCode: null,
  occurredAt: "2026-07-13T00:00:00.000000Z",
  nextRetryAt: null,
  consecutiveFailures: 1,
  lastErrorCode: null,
  createdAt: "2026-07-13T00:00:00.000000Z",
  sentAt: "2026-07-13T00:01:00.000000Z",
};
const stubs = {
  Button: {
    template:
      "<button @click=\"$emit('click')\"><slot />{{ $attrs.label }}</button>",
  },
  Dialog: {
    name: "Dialog",
    emits: ["update:visible"],
    template: '<div><slot /><slot name="footer" /></div>',
  },
  Message: { template: "<div><slot /></div>" },
  Select: {
    name: "Select",
    inheritAttrs: false,
    props: ["modelValue"],
    emits: ["update:modelValue"],
    template: "<select />",
  },
  Tag: { template: "<span>{{ $attrs.value }}</span>" },
  RouterLink: { template: "<a><slot /></a>" },
};
describe("backup operations views", () => {
  beforeEach(() => setActivePinia(createPinia()));
  it("renders inventory-based manual selection and read-only permission hint", () => {
    const operations = useBackupOperationsStore();
    operations.loadQueue = vi.fn();
    const policies = usePoliciesStore();
    policies.load = vi.fn();
    useConfigurationInventoryStore().loadHierarchy = vi.fn();
    const wrapper = mount(QueueView, { global: { stubs } });
    expect(wrapper.text()).toContain("read-only");
    expect(wrapper.text()).not.toContain("Gast-ID");
    expect(wrapper.text()).not.toContain("Policy-ID");
  });
  it("renders worker, schedule, resource and audit dashboard projections", () => {
    const store = useBackupOperationsStore();
    store.loadDashboard = vi.fn();
    store.dashboard = {
      workers: {
        collector: {
          status: "ready",
          fresh: true,
          heartbeatAt: "2026-07-13T00:00:00Z",
          expiresAt: "2026-07-13T00:05:00Z",
          nextActionAt: null,
          buildVersion: "2.0",
          currentActivity: "inventory",
        },
        backup: {
          status: "degraded",
          fresh: false,
          heartbeatAt: "2026-07-13T00:00:00Z",
          expiresAt: "2026-07-13T00:05:00Z",
          nextActionAt: null,
          buildVersion: "2.0",
          currentActivity: null,
        },
      },
      collectorSchedule: {
        nextScanAt: "2026-07-13T00:02:00Z",
        lastAttemptStartedAt: null,
        lastAttemptFinishedAt: null,
        lastSuccessfulAppliedAt: null,
      },
      resources: { systems: 2, nodes: 3, guests: 4, targets: 5, policies: 6 },
      requestsByState: {
        pending: 1,
        retry_wait: 0,
        leased: 0,
        starting: 0,
        running: 1,
        reconcile_required: 0,
        succeeded: 2,
        failed: 1,
        cancelled: 0,
        unknown: 1,
      },
      requestsByReason: {
        manual: 1,
        never_backed_up: 2,
        max_age: 3,
        bytes_written: 4,
      },
      runsByState: {
        awaiting_submission: 0,
        reconcile_required: 0,
        running: 1,
        cancel_requested: 0,
        succeeded: 2,
        failed: 1,
        cancelled: 0,
        unknown: 1,
      },
      oldestPendingAt: "2026-07-13T00:00:00Z",
      staleEvidence: 1,
      shadowBlockers: 2,
      openProblems: 3,
      notifications: {
        byState: { pending: 1, claimed: 0, sent: 2 },
        oldestUnsentAt: "2026-07-13T00:00:00Z",
        lastErrorCode: null,
        nextDeliveryAttemptAt: null,
      },
      lastSuccessfulRun: {
        runId: id,
        guestName: "vm-success",
        vmid: 101,
        nodeName: "pve-a",
        targetName: "pbs-primary",
        finishedAt: "2026-07-13T00:01:00.000000Z",
      },
      recentAuditEvents: [
        {
          id,
          occurredAt: "2026-07-13T00:00:00Z",
          eventType: "manual_backup_requested",
          outcome: "succeeded",
          subjectType: "backup_request",
          reasonCode: null,
        },
      ],
      auditVisible: true,
    };
    const wrapper = mount(DashboardView, { global: { stubs } });
    expect(wrapper.text()).toContain("Collector Worker");
    expect(wrapper.text()).toContain("Heartbeat veraltet");
    expect(wrapper.text()).toContain("Manuelles Backup angefordert");
    expect(wrapper.text()).toContain("Aktive Gäste");
  });
  it("renders explicit dashboard loading, empty and error states", async () => {
    const store = useBackupOperationsStore();
    store.loadDashboard = vi.fn();
    store.loading = true;
    const wrapper = mount(DashboardView, { global: { stubs } });
    expect(wrapper.get('[role="status"]').text()).toContain("geladen");
    store.loading = false;
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Keine Betriebsprojektion");
    store.error = "Dashboard nicht erreichbar";
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Dashboard nicht erreichbar");
    expect(wrapper.text()).not.toContain("Keine Betriebsprojektion");
  });
  it("renders and paginates runs, notifications and delivery details", async () => {
    const store = useBackupOperationsStore();
    store.loadRuns = vi.fn();
    store.loadNotifications = vi.fn();
    store.runsPage = { limit: 25, count: 1, hasMore: true, nextCursor: "next" };
    store.notificationPage = {
      limit: 25,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    store.runs = [
      {
        ...baseRun,
        attempt: 2,
      },
    ];
    store.notifications = [
      {
        ...baseNotification,
        attempt: 2,
        deliveryAttempts: 2,
      },
    ];
    store.notificationHealth = {
      byState: { pending: 1, claimed: 0, sent: 2 },
      oldestUnsentAt: "2026-07-13T00:00:00.000000Z",
      lastErrorCode: "webhook_rejected",
      nextDeliveryAttemptAt: "2026-07-13T00:05:00.000000Z",
    };
    const wrapper = mount(RunsView, { global: { stubs } });
    expect(wrapper.text()).toContain("Versuch 2");
    expect(wrapper.text()).toContain("Zustellversuche 2");
    expect(wrapper.text()).toContain("Ausstehend 1");
    expect(wrapper.text()).toContain("webhook_rejected");
    expect(wrapper.text()).toContain("read-only");
    for (const button of wrapper.findAll("button"))
      await button.trigger("click");
    expect(store.loadRuns).toHaveBeenCalledWith(undefined, true);
    expect(store.loadNotifications).toHaveBeenCalledWith(undefined, true);
  });
  it("applies the closed queue, run and notification filters", async () => {
    const store = useBackupOperationsStore();
    store.loadQueue = vi.fn();
    store.loadRuns = vi.fn();
    store.loadNotifications = vi.fn();
    usePoliciesStore().load = vi.fn();

    const queue = mount(QueueView, { global: { stubs } });
    store.queueState = "retry_wait";
    await queue
      .get('form[aria-label="Backup-Queue filtern"]')
      .trigger("submit");
    expect(store.loadQueue).toHaveBeenLastCalledWith();

    const runs = mount(RunsView, { global: { stubs } });
    store.runState = "unknown";
    store.notificationKind = "attention_required";
    await runs.get('form[aria-label="Backup-Läufe filtern"]').trigger("submit");
    await runs
      .get('form[aria-label="Matrix-Meldungen filtern"]')
      .trigger("submit");
    expect(store.loadRuns).toHaveBeenLastCalledWith();
    expect(store.loadNotifications).toHaveBeenLastCalledWith();
  });
  it("renders and paginates ambiguous events and task logs", async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: "/runs/:id", component: RunDetailView }],
    });
    await router.push(`/runs/${id}`);
    await router.isReady();
    const store = useBackupOperationsStore();
    store.loadRun = vi.fn();
    store.loadMoreRequestEvents = vi.fn();
    store.loadMoreRunEvents = vi.fn();
    store.loadMoreLogs = vi.fn();
    store.detail = {
      ...baseRun,
      state: "reconcile_required",
      stopAttemptStatus: "dispatch_unknown",
      stopAttemptClaimedAt: "2026-07-13T00:00:01.000000Z",
      stopAttemptResolvedAt: "2026-07-13T00:00:02.000000Z",
      stopFailureCode: "cancel_dispatch_unknown",
    };
    store.requestEventPage = {
      limit: 25,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    store.eventPage = {
      limit: 25,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    store.logPage = { limit: 25, count: 1, hasMore: true, nextCursor: "next" };
    store.requestEvents = [
      {
        id,
        sequence: 1,
        type: "manual_requested",
        state: "pending",
        occurredAt: "2026-07-13T00:00:00Z",
      },
    ];
    store.events = [
      {
        id,
        sequence: 1,
        type: "submission_ambiguous",
        state: "reconcile_required",
        occurredAt: "2026-07-13T00:00:00Z",
      },
    ];
    store.logs = [
      { lineNo: 1, observedAt: "2026-07-13T00:00:00Z", content: "safe log" },
    ];
    const wrapper = mount(RunDetailView, {
      global: { plugins: [router], stubs },
    });
    expect(wrapper.text()).toContain("keinen automatischen vzdump-Retry");
    expect(wrapper.text()).toContain("keinen zweiten PVE-Stop-Aufruf");
    expect(wrapper.text()).toContain("Stop-Übergabe nach Crash unklar");
    for (const button of wrapper.findAll("button"))
      await button.trigger("click");
    expect(store.loadMoreRequestEvents).toHaveBeenCalled();
    expect(store.loadMoreRunEvents).toHaveBeenCalled();
    expect(store.loadMoreLogs).toHaveBeenCalled();
  });
  it("submits an inventory-selected policy/guest and confirms cancel", async () => {
    useAuthStore().$patch({
      principal: {
        id,
        username: "operator",
        permissions: ["inventory.read", "backup_operations.manage"],
      },
      csrfToken: "csrf",
    });
    const operations = useBackupOperationsStore();
    operations.loadQueue = vi.fn();
    operations.manual = vi.fn().mockResolvedValue(true);
    operations.cancel = vi.fn().mockResolvedValue(true);
    operations.queue = [
      {
        ...baseRequest,
      },
    ];
    const policies = usePoliciesStore();
    policies.loadAllEnabledForOperations = vi.fn();
    policies.operationItems = [
      {
        id,
        revision: 3,
        status: "enabled",
        displayName: "Daily",
        connectionId: id,
        clusterId: id,
      } as never,
    ];
    useConfigurationInventoryStore().loadHierarchy = vi.fn();
    const wrapper = mount(QueueView, { global: { stubs } });
    const selects = wrapper.findAllComponents({ name: "Select" });
    await selects[1]?.vm.$emit("update:modelValue", id);
    await flushPromises();
    await selects[2]?.vm.$emit("update:modelValue", id);
    await wrapper.find("form.operation-command").trigger("submit");
    expect(operations.manual).toHaveBeenCalled();
    const buttons = wrapper.findAll("button");
    await buttons
      .find((button) => button.text().includes("Abbrechen"))
      ?.trigger("click");
    await buttons
      .find((button) => button.text().includes("Abbruch bestätigen"))
      ?.trigger("click");
    expect(operations.cancel).toHaveBeenCalled();
  });
  it("keeps incomplete manual and cancelled confirmations closed", async () => {
    useAuthStore().$patch({
      principal: {
        id,
        username: "operator",
        permissions: ["inventory.read", "backup_operations.manage"],
      },
    });
    const operations = useBackupOperationsStore();
    operations.loadQueue = vi.fn();
    operations.manual = vi.fn();
    operations.cancel = vi.fn().mockResolvedValue(false);
    const policies = usePoliciesStore();
    policies.load = vi.fn();
    const wrapper = mount(QueueView, { global: { stubs } });
    await wrapper.find("form.operation-command").trigger("submit");
    const dialog = wrapper.findComponent({ name: "Dialog" });
    await dialog.vm.$emit("update:visible", true);
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Abbruch bestätigen"))
      ?.trigger("click");
    expect(operations.manual).not.toHaveBeenCalled();
    expect(operations.cancel).not.toHaveBeenCalled();
  });
});
