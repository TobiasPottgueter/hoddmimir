import { computed, ref } from "vue";
import { defineStore } from "pinia";
import { apiErrorMessage } from "@/api/errors";
import {
  operationsApi,
  type BackupNotification,
  type BackupRequest,
  type BackupRun,
  type OperationsApi,
} from "@/api/operationsApi";
import type {
  BackupEvent,
  BackupLogEntry,
  BackupNotificationHealth,
  BackupRequestState,
  BackupRunState,
  ManualBackupRequest,
  OperationsDashboard,
} from "@/api/generated/types.gen";
import type { CursorPageMetadata } from "@/api/pagination";
import { useAuthStore } from "@/stores/auth";

const page = (): CursorPageMetadata => ({
  limit: 25,
  count: 0,
  hasMore: false,
  nextCursor: null,
});
export const useBackupOperationsStore = defineStore("backup-operations", () => {
  const dashboard = ref<OperationsDashboard | null>(null);
  const queue = ref<BackupRequest[]>([]);
  const queuePage = ref(page());
  const runs = ref<BackupRun[]>([]);
  const runsPage = ref(page());
  const notifications = ref<BackupNotification[]>([]);
  const notificationPage = ref(page());
  const notificationHealth = ref<BackupNotificationHealth | null>(null);
  const queueState = ref<BackupRequestState | "all">("all");
  const runState = ref<BackupRunState | "all">("all");
  const notificationKind = ref<
    "all" | "failure" | "attention_required" | "recovery"
  >("all");
  const detail = ref<BackupRun | null>(null);
  const requestEvents = ref<BackupEvent[]>([]);
  const requestEventPage = ref(page());
  const events = ref<BackupEvent[]>([]);
  const eventPage = ref(page());
  const logs = ref<BackupLogEntry[]>([]);
  const logPage = ref(page());
  const loading = ref(false);
  const error = ref<string | null>(null);
  const emptyQueue = computed(() => !loading.value && queue.value.length === 0);
  async function execute(operation: () => Promise<void>): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      await operation();
    } catch (failure) {
      error.value = apiErrorMessage(failure);
    } finally {
      loading.value = false;
    }
  }
  async function loadDashboard(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    await execute(async () => {
      dashboard.value = await api.dashboard();
    });
  }
  async function loadQueue(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    await execute(async () => {
      const result = await api.queue(
        queuePage.value.limit,
        append ? (queuePage.value.nextCursor ?? undefined) : undefined,
        queueState.value === "all" ? undefined : queueState.value,
      );
      queue.value = append ? [...queue.value, ...result.items] : result.items;
      queuePage.value = result.page;
    });
  }
  async function loadRuns(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    await execute(async () => {
      const result = await api.runs(
        runsPage.value.limit,
        append ? (runsPage.value.nextCursor ?? undefined) : undefined,
        runState.value === "all" ? undefined : runState.value,
      );
      runs.value = append ? [...runs.value, ...result.items] : result.items;
      runsPage.value = result.page;
    });
  }
  async function loadNotifications(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    await execute(async () => {
      const [result, health] = await Promise.all([
        api.notifications(
          notificationPage.value.limit,
          append ? (notificationPage.value.nextCursor ?? undefined) : undefined,
          notificationKind.value === "all" ? undefined : notificationKind.value,
        ),
        api.notificationHealth(),
      ]);
      notifications.value = append
        ? [...notifications.value, ...result.items]
        : result.items;
      notificationPage.value = result.page;
      notificationHealth.value = health;
    });
  }
  async function loadRun(
    id: string,
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    await execute(async () => {
      const run = await api.run(id);
      detail.value = run;
      const [requests, runEvents, lines] = await Promise.all([
        api.requestEvents(run.requestId, 25),
        api.runEvents(id, 25),
        api.logs(id, 100),
      ]);
      requestEvents.value = requests.items;
      requestEventPage.value = requests.page;
      events.value = runEvents.items;
      eventPage.value = runEvents.page;
      logs.value = lines.items;
      logPage.value = lines.page;
    });
  }
  async function loadMoreRequestEvents(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (detail.value === null || !requestEventPage.value.hasMore) return;
    await execute(async () => {
      const result = await api.requestEvents(
        detail.value!.requestId,
        requestEventPage.value.limit,
        requestEventPage.value.nextCursor ?? undefined,
      );
      requestEvents.value = [...requestEvents.value, ...result.items];
      requestEventPage.value = result.page;
    });
  }
  async function loadMoreRunEvents(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (detail.value === null || !eventPage.value.hasMore) return;
    await execute(async () => {
      const result = await api.runEvents(
        detail.value!.id,
        eventPage.value.limit,
        eventPage.value.nextCursor ?? undefined,
      );
      events.value = [...events.value, ...result.items];
      eventPage.value = result.page;
    });
  }
  async function loadMoreLogs(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (detail.value === null || !logPage.value.hasMore || loading.value)
      return;
    await execute(async () => {
      const lines = await api.logs(
        detail.value!.id,
        logPage.value.limit,
        logPage.value.nextCursor ?? undefined,
      );
      logs.value = [...logs.value, ...lines.items];
      logPage.value = lines.page;
    });
  }
  async function mutation(
    operation: (csrf: string) => Promise<void>,
    api: OperationsApi,
  ): Promise<boolean> {
    const csrf = useAuthStore().csrfToken;
    if (csrf === null) return false;
    loading.value = true;
    error.value = null;
    try {
      await operation(csrf);
      return true;
    } catch (failure) {
      const status =
        typeof failure === "object" && failure !== null
          ? (failure as { httpStatus?: number }).httpStatus
          : undefined;
      const message =
        status === 409
          ? "Die Revision ist veraltet. Die Queue wurde neu geladen; bitte prüfe und wiederhole die Aktion."
          : status === 422
            ? "Die Aktion ist durch den aktuellen Backupzustand blockiert."
            : apiErrorMessage(failure);
      if (status === 409) await loadQueue(api);
      error.value = message;
      return false;
    } finally {
      loading.value = false;
    }
  }
  async function manual(
    body: ManualBackupRequest,
    api: OperationsApi = operationsApi,
  ): Promise<boolean> {
    const ok = await mutation(
      (csrf) =>
        api.manual(body, csrf, crypto.randomUUID()).then(() => undefined),
      api,
    );
    if (ok) await loadQueue(api);
    return ok;
  }
  async function cancel(
    request: BackupRequest,
    api: OperationsApi = operationsApi,
  ): Promise<boolean> {
    const ok = await mutation(
      (csrf) =>
        api
          .cancel(request.id, request.revision, csrf, crypto.randomUUID())
          .then(() => undefined),
      api,
    );
    if (ok) await loadQueue(api);
    return ok;
  }
  return {
    dashboard,
    queue,
    queuePage,
    runs,
    runsPage,
    notifications,
    notificationPage,
    notificationHealth,
    queueState,
    runState,
    notificationKind,
    detail,
    requestEvents,
    requestEventPage,
    events,
    eventPage,
    logs,
    logPage,
    loading,
    error,
    emptyQueue,
    loadDashboard,
    loadQueue,
    loadRuns,
    loadNotifications,
    loadRun,
    loadMoreRequestEvents,
    loadMoreRunEvents,
    loadMoreLogs,
    manual,
    cancel,
  };
});
