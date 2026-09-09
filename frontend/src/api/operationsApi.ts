import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  cancelBackupRequest,
  getBackupNotificationHealth,
  getBackupRun,
  getOperationsDashboard,
  listBackupNotifications,
  listBackupRequestEvents,
  listBackupRequests,
  listBackupRunEvents,
  listBackupRunLogs,
  listBackupRuns,
  requestManualBackup,
} from "@/api/generated/sdk.gen";
import type {
  BackupEventPage,
  BackupLogPage,
  BackupNotificationHealth,
  BackupNotificationPage,
  BackupOperationResult,
  BackupRequestPage,
  BackupRequestState,
  BackupRun,
  BackupRunPage,
  BackupRunState,
  ManualBackupRequest,
  OperationsDashboard,
  ListBackupRunsData,
} from "@/api/generated/types.gen";

export type {
  BackupRequest,
  BackupRun,
  BackupNotification,
} from "@/api/generated/types.gen";

export type RunHistoryFilters = Pick<
  NonNullable<ListBackupRunsData["query"]>,
  | "guestId"
  | "nodeId"
  | "targetId"
  | "vmid"
  | "search"
  | "startedFrom"
  | "startedBefore"
>;

export interface OperationsApi {
  dashboard(): Promise<OperationsDashboard>;
  queue(
    limit: number,
    cursor?: string,
    state?: BackupRequestState,
  ): Promise<BackupRequestPage>;
  runs(
    limit: number,
    cursor?: string,
    state?: BackupRunState,
    filters?: RunHistoryFilters,
  ): Promise<BackupRunPage>;
  run(id: string): Promise<BackupRun>;
  requestEvents(
    id: string,
    limit: number,
    cursor?: string,
  ): Promise<BackupEventPage>;
  runEvents(
    id: string,
    limit: number,
    cursor?: string,
  ): Promise<BackupEventPage>;
  logs(id: string, limit: number, cursor?: string): Promise<BackupLogPage>;
  notifications(
    limit: number,
    cursor?: string,
    kind?: "failure" | "attention_required" | "recovery",
  ): Promise<BackupNotificationPage>;
  notificationHealth(): Promise<BackupNotificationHealth>;
  manual(
    body: ManualBackupRequest,
    csrfToken: string,
    idempotencyKey: string,
  ): Promise<BackupOperationResult>;
  cancel(
    id: string,
    expectedRevision: number,
    csrfToken: string,
    idempotencyKey: string,
  ): Promise<BackupOperationResult>;
}

export function createOperationsApi(baseUrl = ""): OperationsApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );
  const query = (limit: number, cursor?: string) => ({
    limit,
    ...(cursor === undefined ? {} : { cursor }),
  });
  return {
    async dashboard() {
      return (await getOperationsDashboard({ client, throwOnError: true }))
        .data;
    },
    async queue(limit, cursor, state) {
      return (
        await listBackupRequests({
          client,
          query: {
            ...query(limit, cursor),
            ...(state === undefined ? {} : { state }),
          },
          throwOnError: true,
        })
      ).data;
    },
    async runs(limit, cursor, state, filters = {}) {
      return (
        await listBackupRuns({
          client,
          query: {
            ...filters,
            ...query(limit, cursor),
            ...(state === undefined ? {} : { state }),
          },
          throwOnError: true,
        })
      ).data;
    },
    async run(id) {
      return (await getBackupRun({ client, path: { id }, throwOnError: true }))
        .data;
    },
    async requestEvents(id, limit, cursor) {
      return (
        await listBackupRequestEvents({
          client,
          path: { id },
          query: query(limit, cursor),
          throwOnError: true,
        })
      ).data;
    },
    async runEvents(id, limit, cursor) {
      return (
        await listBackupRunEvents({
          client,
          path: { id },
          query: query(limit, cursor),
          throwOnError: true,
        })
      ).data;
    },
    async logs(id, limit, cursor) {
      return (
        await listBackupRunLogs({
          client,
          path: { id },
          query: query(limit, cursor),
          throwOnError: true,
        })
      ).data;
    },
    async notifications(limit, cursor, kind) {
      return (
        await listBackupNotifications({
          client,
          query: {
            ...query(limit, cursor),
            ...(kind === undefined ? {} : { kind }),
          },
          throwOnError: true,
        })
      ).data;
    },
    async notificationHealth() {
      return (await getBackupNotificationHealth({ client, throwOnError: true }))
        .data;
    },
    async manual(body, csrfToken, idempotencyKey) {
      return (
        await requestManualBackup({
          client,
          body,
          headers: {
            "X-CSRF-Token": csrfToken,
            "Idempotency-Key": idempotencyKey,
          },
          throwOnError: true,
        })
      ).data;
    },
    async cancel(id, expectedRevision, csrfToken, idempotencyKey) {
      return (
        await cancelBackupRequest({
          client,
          path: { id },
          body: { expectedRevision },
          headers: {
            "X-CSRF-Token": csrfToken,
            "Idempotency-Key": idempotencyKey,
          },
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const operationsApi = createOperationsApi();
