import { computed, reactive, ref } from "vue";
import { defineStore } from "pinia";
import { apiErrorMessage } from "@/api/errors";
import {
  operationsApi,
  type BackupNotification,
  type BackupRequest,
  type BackupRun,
  type OperationsApi,
  type RunHistoryFilters,
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

export interface ReadState {
  loading: boolean;
  error: string | null;
  loaded: boolean;
  updatedAt: string | null;
}
const readState = (): ReadState => ({
  loading: false,
  error: null,
  loaded: false,
  updatedAt: null,
});
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
  const runFilters = ref<RunHistoryFilters>({});
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
  const states = reactive({
    dashboard: readState(),
    queue: readState(),
    runs: readState(),
    notifications: readState(),
    detail: readState(),
  });
  type Section = keyof typeof states;
  const generation: Record<Section, number> = {
    dashboard: 0,
    queue: 0,
    runs: 0,
    notifications: 0,
    detail: 0,
  };
  const mutationPending = ref(false);
  const mutationError = ref<string | null>(null);
  const mutationSuccess = ref<string | null>(null);
  const emptyQueue = computed(
    () =>
      states.queue.loaded &&
      !states.queue.loading &&
      !states.queue.error &&
      queue.value.length === 0,
  );
  async function read<T>(
    section: Section,
    operation: () => Promise<T>,
    apply: (value: T) => void,
  ): Promise<void> {
    const current = ++generation[section];
    const state = states[section];
    state.loading = true;
    state.error = null;
    try {
      const value = await operation();
      if (current !== generation[section]) return;
      apply(value);
      state.loaded = true;
      state.updatedAt = new Date().toISOString();
    } catch (failure) {
      if (current === generation[section])
        state.error = apiErrorMessage(failure);
    } finally {
      if (current === generation[section]) state.loading = false;
    }
  }
  async function loadDashboard(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    await read(
      "dashboard",
      () => api.dashboard(),
      (value) => {
        dashboard.value = value;
      },
    );
  }
  async function loadQueue(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    if (append && states.queue.loading) return;
    await read(
      "queue",
      () =>
        api.queue(
          queuePage.value.limit,
          append ? (queuePage.value.nextCursor ?? undefined) : undefined,
          queueState.value === "all" ? undefined : queueState.value,
        ),
      (result) => {
        queue.value = append ? [...queue.value, ...result.items] : result.items;
        queuePage.value = result.page;
      },
    );
  }
  function setRunFilters(
    state: BackupRunState | "all",
    filters: RunHistoryFilters,
  ): void {
    if (
      runState.value === state &&
      JSON.stringify(runFilters.value) === JSON.stringify(filters)
    )
      return;
    ++generation.runs;
    runState.value = state;
    runFilters.value = { ...filters };
    runs.value = [];
    runsPage.value = page();
    Object.assign(states.runs, readState());
  }
  async function loadRuns(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    if (
      append &&
      (states.runs.loading || !runsPage.value.hasMore || states.runs.error)
    )
      return;
    await read(
      "runs",
      () =>
        api.runs(
          runsPage.value.limit,
          append ? (runsPage.value.nextCursor ?? undefined) : undefined,
          runState.value === "all" ? undefined : runState.value,
          { ...runFilters.value },
        ),
      (result) => {
        runs.value = append ? [...runs.value, ...result.items] : result.items;
        runsPage.value = result.page;
      },
    );
  }
  async function loadNotifications(
    api: OperationsApi = operationsApi,
    append = false,
  ): Promise<void> {
    if (append && states.notifications.loading) return;
    await read(
      "notifications",
      () =>
        Promise.all([
          api.notifications(
            notificationPage.value.limit,
            append
              ? (notificationPage.value.nextCursor ?? undefined)
              : undefined,
            notificationKind.value === "all"
              ? undefined
              : notificationKind.value,
          ),
          api.notificationHealth(),
        ]),
      ([result, health]) => {
        notifications.value = append
          ? [...notifications.value, ...result.items]
          : result.items;
        notificationPage.value = result.page;
        notificationHealth.value = health;
      },
    );
  }
  async function loadRun(
    id: string,
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (detail.value?.id !== id) {
      detail.value = null;
      requestEvents.value = [];
      events.value = [];
      logs.value = [];
      states.detail.loaded = false;
      states.detail.updatedAt = null;
    }
    await read(
      "detail",
      async () => {
        const run = await api.run(id);
        const [requests, runEvents, lines] = await Promise.all([
          api.requestEvents(run.requestId, 25),
          api.runEvents(id, 25),
          api.logs(id, 100),
        ]);
        return { run, requests, runEvents, lines };
      },
      ({ run, requests, runEvents, lines }) => {
        detail.value = run;
        requestEvents.value = requests.items;
        requestEventPage.value = requests.page;
        events.value = runEvents.items;
        eventPage.value = runEvents.page;
        logs.value = lines.items;
        logPage.value = lines.page;
      },
    );
  }
  async function loadMoreRequestEvents(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (
      detail.value === null ||
      !requestEventPage.value.hasMore ||
      states.detail.loading
    )
      return;
    const requestId = detail.value.requestId;
    await read(
      "detail",
      () =>
        api.requestEvents(
          requestId,
          25,
          requestEventPage.value.nextCursor ?? undefined,
        ),
      (result) => {
        requestEvents.value = [...requestEvents.value, ...result.items];
        requestEventPage.value = result.page;
      },
    );
  }
  async function loadMoreRunEvents(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (
      detail.value === null ||
      !eventPage.value.hasMore ||
      states.detail.loading
    )
      return;
    const runId = detail.value.id;
    await read(
      "detail",
      () => api.runEvents(runId, 25, eventPage.value.nextCursor ?? undefined),
      (result) => {
        events.value = [...events.value, ...result.items];
        eventPage.value = result.page;
      },
    );
  }
  async function loadMoreLogs(
    api: OperationsApi = operationsApi,
  ): Promise<void> {
    if (
      detail.value === null ||
      !logPage.value.hasMore ||
      states.detail.loading
    )
      return;
    const runId = detail.value.id;
    await read(
      "detail",
      () => api.logs(runId, 25, logPage.value.nextCursor ?? undefined),
      (result) => {
        logs.value = [...logs.value, ...result.items];
        logPage.value = result.page;
      },
    );
  }
  async function mutation(
    operation: (csrf: string) => Promise<void>,
    api: OperationsApi,
  ): Promise<boolean> {
    const csrf = useAuthStore().csrfToken;
    if (csrf === null || mutationPending.value) return false;
    mutationPending.value = true;
    mutationError.value = null;
    mutationSuccess.value = null;
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
      mutationError.value = message;
      return false;
    } finally {
      mutationPending.value = false;
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
    if (ok) {
      mutationSuccess.value =
        "Backup-Anforderung gespeichert. Der Backup-Worker prüft die Startbedingungen.";
      await loadQueue(api);
    }
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
    if (ok) {
      mutationSuccess.value =
        "Abbruch angefordert. Der Backup-Worker übernimmt die weitere Verarbeitung.";
      await loadQueue(api);
    }
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
    runFilters,
    setRunFilters,
    notificationKind,
    detail,
    requestEvents,
    requestEventPage,
    events,
    eventPage,
    logs,
    logPage,
    dashboardStatus: states.dashboard,
    queueStatus: states.queue,
    runsStatus: states.runs,
    notificationStatus: states.notifications,
    detailStatus: states.detail,
    mutationPending,
    mutationError,
    mutationSuccess,
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
