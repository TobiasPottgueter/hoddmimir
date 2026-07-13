import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  createBackupTarget,
  updateBackupTarget,
  enableBackupTarget,
  disableBackupTarget,
  createPolicy,
  updatePolicy,
  enablePolicy,
  disablePolicy,
  upsertPolicySelection,
  disablePolicySelection,
  upsertPolicyGuestOverrides,
  disablePolicyGuestOverrides,
  listBackupTargetCandidates,
  listBackupTargets,
} from "@/api/generated/sdk.gen";
import type {
  BackupTargetCandidate,
  BackupTargetCandidatePage,
  ConfiguredBackupTarget,
  ConfiguredBackupTargetPage,
  TargetCommandRequest,
  PolicyCommandRequest,
  RevisionCommandRequest,
  BulkConfigurationCommandRequest,
  ConfigurationCommandResult,
} from "@/api/generated/types.gen";
import type { CursorPage, CursorPageQuery } from "@/api/pagination";

export interface BackupTargetCandidateQuery extends CursorPageQuery {
  connectionId?: string;
  clusterId?: string;
}

export interface ConfiguredBackupTargetQuery extends CursorPageQuery {
  search?: string;
  enabled?: boolean;
}

export interface ConfigurationCommandHeaders {
  idempotencyKey: string;
  csrfToken: string;
}

export type ConfigurationCommandFailure =
  | { kind: "invalid" }
  | { kind: "permission" }
  | { kind: "conflict"; currentRevision: number }
  | { kind: "blocked"; blockers: string[] }
  | { kind: "unavailable" }
  | { kind: "unknown" };

export interface ConfigurationApi {
  getBackupTargetCandidates(
    query: BackupTargetCandidateQuery,
  ): Promise<CursorPage<BackupTargetCandidate>>;
  getBackupTargets(
    query: ConfiguredBackupTargetQuery,
  ): Promise<CursorPage<ConfiguredBackupTarget>>;
}

export interface ConfigurationMutationApi {
  createBackupTarget(
    body: TargetCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  updateBackupTarget(
    id: string,
    body: TargetCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  enableBackupTarget(
    id: string,
    body: RevisionCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  disableBackupTarget(
    id: string,
    body: RevisionCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  createPolicy(
    body: PolicyCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  updatePolicy(
    id: string,
    body: PolicyCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  enablePolicy(
    id: string,
    body: RevisionCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  disablePolicy(
    id: string,
    body: RevisionCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  upsertPolicySelection(
    id: string,
    body: BulkConfigurationCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  disablePolicySelection(
    id: string,
    body: BulkConfigurationCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  upsertPolicyGuestOverrides(
    id: string,
    body: BulkConfigurationCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
  disablePolicyGuestOverrides(
    id: string,
    body: BulkConfigurationCommandRequest,
    headers: ConfigurationCommandHeaders,
  ): Promise<ConfigurationCommandResult>;
}

export interface FullConfigurationApi
  extends ConfigurationApi, ConfigurationMutationApi {}

function commandHeaders(headers: ConfigurationCommandHeaders) {
  return {
    "Idempotency-Key": headers.idempotencyKey,
    "X-CSRF-Token": headers.csrfToken,
  };
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

export function configurationCommandFailure(
  error: unknown,
): ConfigurationCommandFailure {
  const outer = record(error);
  const status = outer?.httpStatus;
  const payload = record(outer?.payload);
  const nested = record(payload?.error);
  if (status === 400) return { kind: "invalid" };
  if (status === 403) return { kind: "permission" };
  if (status === 409 && typeof nested?.currentRevision === "number")
    return { kind: "conflict", currentRevision: nested.currentRevision };
  if (
    status === 422 &&
    Array.isArray(nested?.blockers) &&
    nested.blockers.every((value) => typeof value === "string")
  )
    return { kind: "blocked", blockers: nested.blockers };
  if (status === 503) return { kind: "unavailable" };
  return { kind: "unknown" };
}

export function createConfigurationApi(baseUrl = ""): FullConfigurationApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );

  return {
    async getBackupTargetCandidates(query) {
      const result = (
        await listBackupTargetCandidates({
          client,
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
            ...(query.connectionId === undefined
              ? {}
              : { connectionId: query.connectionId }),
            ...(query.clusterId === undefined
              ? {}
              : { clusterId: query.clusterId }),
          },
          throwOnError: true,
        })
      ).data satisfies BackupTargetCandidatePage;
      return result;
    },
    async getBackupTargets(query) {
      const result = (
        await listBackupTargets({
          client,
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
            ...(query.search === undefined ? {} : { search: query.search }),
            ...(query.enabled === undefined ? {} : { enabled: query.enabled }),
          },
          throwOnError: true,
        })
      ).data satisfies ConfiguredBackupTargetPage;
      return result;
    },
    async createBackupTarget(body, headers) {
      return (
        await createBackupTarget({
          client,
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async updateBackupTarget(id, body, headers) {
      return (
        await updateBackupTarget({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async enableBackupTarget(id, body, headers) {
      return (
        await enableBackupTarget({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disableBackupTarget(id, body, headers) {
      return (
        await disableBackupTarget({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async createPolicy(body, headers) {
      return (
        await createPolicy({
          client,
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async updatePolicy(id, body, headers) {
      return (
        await updatePolicy({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async enablePolicy(id, body, headers) {
      return (
        await enablePolicy({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disablePolicy(id, body, headers) {
      return (
        await disablePolicy({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async upsertPolicySelection(id, body, headers) {
      return (
        await upsertPolicySelection({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disablePolicySelection(id, body, headers) {
      return (
        await disablePolicySelection({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async upsertPolicyGuestOverrides(id, body, headers) {
      return (
        await upsertPolicyGuestOverrides({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disablePolicyGuestOverrides(id, body, headers) {
      return (
        await disablePolicyGuestOverrides({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const configurationApi = createConfigurationApi();
