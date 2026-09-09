import { computed, ref } from "vue";
import { defineStore } from "pinia";
import {
  connectionApi,
  connectionFailure,
  type ConnectionApi,
} from "@/api/connectionApi";
import type {
  ConnectionDetail,
  ConnectionUpdateRequest,
  RevisionCommandRequest,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";

const emptyPage = (): CursorPageMetadata => ({
  limit: 20,
  count: 0,
  hasMore: false,
  nextCursor: null,
});

export const useConnectionsStore = defineStore("connections", () => {
  const items = ref<ConnectionDetail[]>([]);
  const page = ref(emptyPage());
  const selected = ref<ConnectionDetail | null>(null);
  const loading = ref(false);
  const pending = ref(false);
  const error = ref<string | null>(null);
  const success = ref<string | null>(null);
  const conflictRevision = ref<number | null>(null);
  const blockers = ref<string[]>([]);
  const auth = useAuthStore();
  const canManage = computed(() =>
    auth.hasPermission("backup_configuration.manage"),
  );

  async function load(
    api: ConnectionApi = connectionApi,
    append = false,
  ): Promise<void> {
    if (!canManage.value) {
      items.value = [];
      error.value = "Für Verbindungen fehlt backup_configuration.manage.";
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const cursor = append ? pageContinuation(page.value) : undefined;
      const response = await api.list({
        limit: page.value.limit,
        ...(cursor === undefined ? {} : { cursor }),
      });
      pageContinuation(response.page, cursor);
      const details = await Promise.all(
        response.items.map((item) => api.detail(item.id)),
      );
      items.value = append ? [...items.value, ...details] : details;
      page.value = response.page;
    } catch {
      items.value = [];
      page.value = emptyPage();
      error.value = "Die Verbindungen konnten nicht sicher geladen werden.";
    } finally {
      loading.value = false;
    }
  }
  async function loadMore(api: ConnectionApi = connectionApi): Promise<void> {
    if (loading.value || !page.value.hasMore) return;
    await load(api, true);
  }
  async function select(
    id: string,
    api: ConnectionApi = connectionApi,
  ): Promise<void> {
    selected.value = null;
    if (!canManage.value) {
      error.value = "Für Verbindungen fehlt backup_configuration.manage.";
      return;
    }
    loading.value = true;
    try {
      selected.value = await api.detail(id);
    } catch {
      error.value = "Die Verbindung konnte nicht sicher geladen werden.";
    } finally {
      loading.value = false;
    }
  }
  function clearResult(): void {
    error.value = null;
    success.value = null;
    conflictRevision.value = null;
    blockers.value = [];
  }
  async function mutate(
    operation: (
      api: ConnectionApi,
      headers: { csrfToken: string; idempotencyKey: string },
    ) => Promise<unknown>,
    api: ConnectionApi = connectionApi,
    key: () => string = () => crypto.randomUUID(),
  ): Promise<boolean> {
    clearResult();
    if (!canManage.value || auth.csrfToken === null) {
      error.value = "Für diese Aktion fehlt backup_configuration.manage.";
      return false;
    }
    pending.value = true;
    try {
      await operation(api, {
        csrfToken: auth.csrfToken,
        idempotencyKey: key(),
      });
      success.value = "Die Verbindungskonfiguration wurde gespeichert.";
      return true;
    } catch (caught) {
      const failure = connectionFailure(caught);
      error.value = {
        invalid: "Die Eingaben sind ungültig.",
        permission: "Die Berechtigung fehlt.",
        conflict: "Die Verbindung wurde zwischenzeitlich geändert.",
        blocked: "Die Sicherheitsregel verhindert diese Änderung.",
        unavailable: "Die Verbindungsverwaltung ist nicht verfügbar.",
        unknown: "Die Änderung konnte nicht angewendet werden.",
      }[failure.kind];
      if (failure.kind === "conflict")
        conflictRevision.value = failure.currentRevision;
      if (failure.kind === "blocked") blockers.value = failure.blockers;
      return false;
    } finally {
      pending.value = false;
    }
  }
  const update = (
    id: string,
    body: ConnectionUpdateRequest,
    api?: ConnectionApi,
    key?: () => string,
  ) => mutate((c, h) => c.update(id, body, h), api, key);
  const disable = (
    id: string,
    body: RevisionCommandRequest,
    api?: ConnectionApi,
    key?: () => string,
  ) => mutate((c, h) => c.disable(id, body, h), api, key);
  const disableEndpoint = (
    id: string,
    endpointId: string,
    body: RevisionCommandRequest,
    api?: ConnectionApi,
    key?: () => string,
  ) => mutate((c, h) => c.disableEndpoint(id, endpointId, body, h), api, key);
  return {
    items,
    page,
    selected,
    loading,
    pending,
    error,
    success,
    conflictRevision,
    blockers,
    canManage,
    load,
    loadMore,
    select,
    clearResult,
    update,
    disable,
    disableEndpoint,
  };
});
