import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  disableConnection,
  disableConnectionEndpoint,
  getConnection,
  listConnections,
  updateConnection,
} from "@/api/generated/sdk.gen";
import type {
  ConfigurationCommandResult,
  ConnectionDetail,
  ConnectionList,
  ConnectionUpdateRequest,
  RevisionCommandRequest,
} from "@/api/generated/types.gen";
import type { CursorPageQuery } from "@/api/pagination";
import {
  administrationFailure,
  type AdministrationFailure,
} from "@/api/administrationApi";

export interface ConnectionHeaders {
  csrfToken: string;
  idempotencyKey: string;
}
export interface ConnectionApi {
  list(query?: CursorPageQuery): Promise<ConnectionList>;
  detail(id: string): Promise<ConnectionDetail>;
  update(
    id: string,
    body: ConnectionUpdateRequest,
    headers: ConnectionHeaders,
  ): Promise<ConfigurationCommandResult>;
  disable(
    id: string,
    body: RevisionCommandRequest,
    headers: ConnectionHeaders,
  ): Promise<ConfigurationCommandResult>;
  disableEndpoint(
    id: string,
    endpointId: string,
    body: RevisionCommandRequest,
    headers: ConnectionHeaders,
  ): Promise<ConfigurationCommandResult>;
}

const commandHeaders = (value: ConnectionHeaders) => ({
  "Idempotency-Key": value.idempotencyKey,
  "X-CSRF-Token": value.csrfToken,
});
export const connectionFailure = (error: unknown): AdministrationFailure =>
  administrationFailure(error);

export function createConnectionApi(baseUrl = ""): ConnectionApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );
  return {
    async list(query = { limit: 50 }) {
      return (await listConnections({ client, query, throwOnError: true }))
        .data;
    },
    async detail(id) {
      return (await getConnection({ client, path: { id }, throwOnError: true }))
        .data;
    },
    async update(id, body, headers) {
      return (
        await updateConnection({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disable(id, body, headers) {
      return (
        await disableConnection({
          client,
          path: { id },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async disableEndpoint(id, endpointId, body, headers) {
      return (
        await disableConnectionEndpoint({
          client,
          path: { id, endpointId },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const connectionApi = createConnectionApi();
