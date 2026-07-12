import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  getCollectorStatus,
  getInventoryOverview,
  listCollectorRuns,
  listCollectorScopes,
  listInventoryResources,
} from "@/api/generated/sdk.gen";
import type {
  CollectorRunPage,
  CollectorScopePage,
  CollectorStatus,
  InventoryOverview,
  InventoryResourceKind,
  InventoryResourcePage,
  InventoryState,
} from "@/api/generated/types.gen";
import type { CursorPage, CursorPageQuery } from "@/api/pagination";

export interface ResourceQuery extends CursorPageQuery {
  kind: InventoryResourceKind;
  connectionId?: string;
  parentId?: string;
  inventoryState?: InventoryState;
  guestType?: "qemu" | "lxc";
}

export type PageQuery = CursorPageQuery;

export interface CollectorScopeQuery extends PageQuery {
  runId: string;
}

export interface InventoryApi {
  getOverview(): Promise<InventoryOverview>;
  getResources(
    query: ResourceQuery,
  ): Promise<CursorPage<InventoryResourcePage["items"][number]>>;
  getCollectorStatus(): Promise<CollectorStatus>;
  getCollectorRuns(
    query: PageQuery,
  ): Promise<CursorPage<CollectorRunPage["items"][number]>>;
  getCollectorScopes(
    query: CollectorScopeQuery,
  ): Promise<CursorPage<CollectorScopePage["items"][number]>>;
}

export function createInventoryApi(baseUrl = ""): InventoryApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );

  return {
    async getOverview() {
      return (await getInventoryOverview({ client, throwOnError: true })).data;
    },
    async getResources(query) {
      const filters = {
        kind: query.kind,
        limit: query.limit,
        ...(query.connectionId === undefined
          ? {}
          : { connectionId: query.connectionId }),
        ...(query.parentId === undefined ? {} : { parentId: query.parentId }),
        ...(query.inventoryState === undefined
          ? {}
          : { inventoryState: query.inventoryState }),
        ...(query.guestType === undefined
          ? {}
          : { guestType: query.guestType }),
      };
      const result = (
        await listInventoryResources({
          client,
          query: {
            ...filters,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
          },
          throwOnError: true,
        })
      ).data;
      return result;
    },
    async getCollectorStatus() {
      return (await getCollectorStatus({ client, throwOnError: true })).data;
    },
    async getCollectorRuns(query) {
      return (
        await listCollectorRuns({
          client,
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
          },
          throwOnError: true,
        })
      ).data;
    },
    async getCollectorScopes(query) {
      const result = (
        await listCollectorScopes({
          client,
          query: {
            runId: query.runId,
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
          },
          throwOnError: true,
        })
      ).data;
      return result;
    },
  };
}

export const inventoryApi = createInventoryApi();
