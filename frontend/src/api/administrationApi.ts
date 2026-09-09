import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  createAdministrationUser,
  disableAdministrationUser,
  getAdministrationHealth,
  getAdministrationAuditEvent,
  listAdministrationAuditEvents,
  listAdministrationRoles,
  listAdministrationUsers,
  replaceAdministrationUserRoles,
  updateAdministrationUser,
} from "@/api/generated/sdk.gen";
import type {
  AdministrationAuditEvent,
  AdministrationAuditEventPage,
  AdministrationHealthReport,
  AdministrationRolePage,
  AdministrationUserPage,
  AuditEventType,
  ConfigurationCommandResult,
  RevisionCommandRequest,
  UserCreateCommandRequestWritable,
  UserRolesCommandRequest,
  UserUpdateCommandRequestWritable,
} from "@/api/generated/types.gen";

export interface AdministrationHeaders {
  csrfToken: string;
  idempotencyKey: string;
}

export interface AdministrationUserQuery {
  limit: number;
  cursor?: string;
  search?: string;
  enabled?: boolean;
}

export interface AdministrationAuditQuery {
  limit: number;
  cursor?: string;
  actorUserId?: string;
  eventType?: AuditEventType;
  outcome?: "succeeded" | "denied";
}

export type AdministrationFailure =
  | { kind: "invalid" }
  | { kind: "permission" }
  | { kind: "conflict"; currentRevision: number }
  | { kind: "blocked"; blockers: string[] }
  | { kind: "unavailable" }
  | { kind: "unknown" };

export interface AdministrationApi {
  health(): Promise<AdministrationHealthReport>;
  users(query: AdministrationUserQuery): Promise<AdministrationUserPage>;
  roles(limit?: number, cursor?: string): Promise<AdministrationRolePage>;
  audit(query: AdministrationAuditQuery): Promise<AdministrationAuditEventPage>;
  auditEvent(id: string): Promise<AdministrationAuditEvent>;
  createUser(
    body: UserCreateCommandRequestWritable,
    headers: AdministrationHeaders,
  ): Promise<ConfigurationCommandResult>;
  updateUser(
    id: string,
    body: UserUpdateCommandRequestWritable,
    headers: AdministrationHeaders,
  ): Promise<ConfigurationCommandResult>;
  disableUser(
    id: string,
    body: RevisionCommandRequest,
    headers: AdministrationHeaders,
  ): Promise<ConfigurationCommandResult>;
  replaceRoles(
    id: string,
    body: UserRolesCommandRequest,
    headers: AdministrationHeaders,
  ): Promise<ConfigurationCommandResult>;
}

function headers(value: AdministrationHeaders) {
  return {
    "Idempotency-Key": value.idempotencyKey,
    "X-CSRF-Token": value.csrfToken,
  };
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

export function administrationFailure(error: unknown): AdministrationFailure {
  const outer = record(error);
  const nested = record(record(outer?.payload)?.error);
  if (outer?.httpStatus === 400) return { kind: "invalid" };
  if (outer?.httpStatus === 403) return { kind: "permission" };
  if (
    outer?.httpStatus === 409 &&
    typeof nested?.currentRevision === "number"
  ) {
    return { kind: "conflict", currentRevision: nested.currentRevision };
  }
  if (
    outer?.httpStatus === 422 &&
    Array.isArray(nested?.blockers) &&
    nested.blockers.every((value) => typeof value === "string")
  ) {
    return { kind: "blocked", blockers: nested.blockers };
  }
  if (outer?.httpStatus === 503) return { kind: "unavailable" };
  return { kind: "unknown" };
}

export function createAdministrationApi(baseUrl = ""): AdministrationApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );
  return {
    async health() {
      return (await getAdministrationHealth({ client, throwOnError: true }))
        .data;
    },
    async users(query) {
      return (
        await listAdministrationUsers({ client, query, throwOnError: true })
      ).data;
    },
    async roles(limit = 100, cursor) {
      return (
        await listAdministrationRoles({
          client,
          query: { limit, ...(cursor === undefined ? {} : { cursor }) },
          throwOnError: true,
        })
      ).data;
    },
    async audit(query) {
      return (
        await listAdministrationAuditEvents({
          client,
          query,
          throwOnError: true,
        })
      ).data;
    },
    async auditEvent(id) {
      return (
        await getAdministrationAuditEvent({
          client,
          path: { id },
          throwOnError: true,
        })
      ).data;
    },
    async createUser(body, commandHeaders) {
      return (
        await createAdministrationUser({
          client,
          body,
          headers: headers(commandHeaders),
          throwOnError: true,
        })
      ).data;
    },
    async updateUser(id, body, commandHeaders) {
      return (
        await updateAdministrationUser({
          client,
          path: { id },
          body,
          headers: headers(commandHeaders),
          throwOnError: true,
        })
      ).data;
    },
    async disableUser(id, body, commandHeaders) {
      return (
        await disableAdministrationUser({
          client,
          path: { id },
          body,
          headers: headers(commandHeaders),
          throwOnError: true,
        })
      ).data;
    },
    async replaceRoles(id, body, commandHeaders) {
      return (
        await replaceAdministrationUserRoles({
          client,
          path: { id },
          body,
          headers: headers(commandHeaders),
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const administrationApi = createAdministrationApi();
