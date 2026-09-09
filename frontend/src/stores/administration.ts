import { computed, ref } from "vue";
import { defineStore } from "pinia";

import {
  administrationApi,
  administrationFailure,
  type AdministrationApi,
  type AdministrationAuditQuery,
  type AdministrationUserQuery,
} from "@/api/administrationApi";
import type {
  AdministrationAuditEvent,
  AdministrationHealthReport,
  AdministrationRole,
  AdministrationUser,
  RevisionCommandRequest,
  UserCreateCommandRequestWritable,
  UserRolesCommandRequest,
  UserUpdateCommandRequestWritable,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";
import { pageContinuation } from "@/api/pagination";

type AdminSection = "users" | "roles" | "audit" | "health";

const readError =
  "Die Administrationsdaten konnten nicht sicher geladen werden.";
const failureMessages = {
  invalid: "Die Eingaben sind ungültig.",
  permission: "Für diese Aktion fehlt die Berechtigung security.manage.",
  conflict: "Der Datensatz wurde zwischenzeitlich geändert.",
  blocked: "Die Sicherheitsregel verhindert diese Änderung.",
  unavailable: "Die Administrationsschnittstelle ist nicht verfügbar.",
  unknown: "Die Änderung konnte nicht angewendet werden.",
} as const;

export const useAdministrationStore = defineStore("administration", () => {
  const section = ref<AdminSection>("users");
  const users = ref<AdministrationUser[]>([]);
  const roles = ref<AdministrationRole[]>([]);
  const auditEvents = ref<AdministrationAuditEvent[]>([]);
  const health = ref<AdministrationHealthReport | null>(null);
  const healthLoading = ref(false);
  const healthError = ref<string | null>(null);
  const selectedAuditEvent = ref<AdministrationAuditEvent | null>(null);
  const loading = ref(false);
  const pending = ref(false);
  const error = ref<string | null>(null);
  const success = ref<string | null>(null);
  const conflictRevision = ref<number | null>(null);
  const blockers = ref<string[]>([]);
  const usersCursor = ref<string | null>(null);
  const rolesCursor = ref<string | null>(null);
  const auditCursor = ref<string | null>(null);

  const auth = useAuthStore();
  const canManageSecurity = computed(() =>
    auth.hasPermission("security.manage"),
  );
  const canReadAudit = computed(() => auth.hasPermission("audit.read"));

  function clearMutation(): void {
    error.value = null;
    success.value = null;
    conflictRevision.value = null;
    blockers.value = [];
  }
  async function loadHealth(
    api: AdministrationApi = administrationApi,
  ): Promise<void> {
    healthLoading.value = true;
    healthError.value = null;
    try {
      health.value = await api.health();
    } catch {
      health.value = null;
      healthError.value = "Die Readiness-Prüfung ist nicht erreichbar.";
    } finally {
      healthLoading.value = false;
    }
  }

  async function loadUsers(
    query: AdministrationUserQuery = { limit: 50 },
    api: AdministrationApi = administrationApi,
    append = false,
  ): Promise<void> {
    if (!canManageSecurity.value) {
      users.value = [];
      error.value = failureMessages.permission;
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const previousCursor = append ? usersCursor.value! : undefined;
      const page = await api.users({
        ...query,
        ...(previousCursor === undefined ? {} : { cursor: previousCursor }),
      });
      pageContinuation(page.page, previousCursor);
      users.value = append ? [...users.value, ...page.items] : page.items;
      usersCursor.value = page.page.nextCursor;
    } catch {
      users.value = [];
      usersCursor.value = null;
      error.value = readError;
    } finally {
      loading.value = false;
    }
  }

  async function loadRoles(
    api: AdministrationApi = administrationApi,
    append = false,
  ): Promise<void> {
    if (!canManageSecurity.value) {
      roles.value = [];
      error.value = failureMessages.permission;
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const previousCursor = append ? rolesCursor.value! : undefined;
      const page = await api.roles(50, previousCursor);
      pageContinuation(page.page, previousCursor);
      roles.value = append ? [...roles.value, ...page.items] : page.items;
      rolesCursor.value = page.page.nextCursor;
    } catch {
      roles.value = [];
      rolesCursor.value = null;
      error.value = readError;
    } finally {
      loading.value = false;
    }
  }

  async function loadAudit(
    query: AdministrationAuditQuery = { limit: 50 },
    api: AdministrationApi = administrationApi,
    append = false,
  ): Promise<void> {
    if (!canReadAudit.value) {
      auditEvents.value = [];
      error.value = "Für das Audit-Log fehlt die Berechtigung audit.read.";
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const previousCursor = append ? auditCursor.value! : undefined;
      const page = await api.audit({
        ...query,
        ...(previousCursor === undefined ? {} : { cursor: previousCursor }),
      });
      pageContinuation(page.page, previousCursor);
      auditEvents.value = append
        ? [...auditEvents.value, ...page.items]
        : page.items;
      auditCursor.value = page.page.nextCursor;
    } catch {
      auditEvents.value = [];
      auditCursor.value = null;
      error.value = readError;
    } finally {
      loading.value = false;
    }
  }
  const loadMoreUsers = (
    query: AdministrationUserQuery = { limit: 50 },
    api?: AdministrationApi,
  ) => (usersCursor.value ? loadUsers(query, api, true) : Promise.resolve());
  const loadMoreRoles = (api?: AdministrationApi) =>
    rolesCursor.value ? loadRoles(api, true) : Promise.resolve();
  const loadMoreAudit = (
    query: AdministrationAuditQuery = { limit: 50 },
    api?: AdministrationApi,
  ) => (auditCursor.value ? loadAudit(query, api, true) : Promise.resolve());

  async function selectAuditEvent(
    id: string,
    api: AdministrationApi = administrationApi,
  ): Promise<void> {
    selectedAuditEvent.value = null;
    if (!canReadAudit.value) {
      error.value = "Für das Audit-Log fehlt die Berechtigung audit.read.";
      return;
    }
    loading.value = true;
    try {
      selectedAuditEvent.value = await api.auditEvent(id);
    } catch {
      error.value = readError;
    } finally {
      loading.value = false;
    }
  }

  async function mutate(
    command: (
      api: AdministrationApi,
      csrfToken: string,
      key: string,
    ) => Promise<{ revision: number }>,
    api: AdministrationApi = administrationApi,
    idempotencyKey: () => string = () => crypto.randomUUID(),
  ): Promise<boolean> {
    clearMutation();
    if (!canManageSecurity.value || auth.csrfToken === null) {
      error.value = failureMessages.permission;
      return false;
    }
    pending.value = true;
    try {
      await command(api, auth.csrfToken, idempotencyKey());
      success.value = "Die Sicherheitskonfiguration wurde gespeichert.";
      return true;
    } catch (caught) {
      const failure = administrationFailure(caught);
      error.value = failureMessages[failure.kind];
      if (failure.kind === "conflict") {
        conflictRevision.value = failure.currentRevision;
      }
      if (failure.kind === "blocked") blockers.value = failure.blockers;
      return false;
    } finally {
      pending.value = false;
    }
  }

  function createUser(
    body: UserCreateCommandRequestWritable,
    api?: AdministrationApi,
    key?: () => string,
  ) {
    return mutate(
      (client, csrfToken, idempotencyKey) =>
        client.createUser(body, { csrfToken, idempotencyKey }),
      api,
      key,
    );
  }

  function updateUser(
    id: string,
    body: UserUpdateCommandRequestWritable,
    api?: AdministrationApi,
    key?: () => string,
  ) {
    return mutate(
      (client, csrfToken, idempotencyKey) =>
        client.updateUser(id, body, { csrfToken, idempotencyKey }),
      api,
      key,
    );
  }

  function disableUser(
    id: string,
    body: RevisionCommandRequest,
    api?: AdministrationApi,
    key?: () => string,
  ) {
    return mutate(
      (client, csrfToken, idempotencyKey) =>
        client.disableUser(id, body, { csrfToken, idempotencyKey }),
      api,
      key,
    );
  }

  function replaceRoles(
    id: string,
    body: UserRolesCommandRequest,
    api?: AdministrationApi,
    key?: () => string,
  ) {
    return mutate(
      (client, csrfToken, idempotencyKey) =>
        client.replaceRoles(id, body, { csrfToken, idempotencyKey }),
      api,
      key,
    );
  }

  return {
    section,
    users,
    roles,
    auditEvents,
    health,
    healthLoading,
    healthError,
    selectedAuditEvent,
    loading,
    pending,
    error,
    success,
    conflictRevision,
    blockers,
    usersCursor,
    rolesCursor,
    auditCursor,
    canManageSecurity,
    canReadAudit,
    clearMutation,
    loadHealth,
    loadUsers,
    loadMoreUsers,
    loadRoles,
    loadMoreRoles,
    loadAudit,
    loadMoreAudit,
    selectAuditEvent,
    createUser,
    updateUser,
    disableUser,
    replaceRoles,
  };
});
