import { inventoryApi, type ResourceQuery } from "@/api/inventoryApi";
import { useAuthStore } from "@/stores/auth";
import { connectionApi } from "@/api/connectionApi";
import { policyApi } from "@/api/policyApi";
import type { InventoryResource } from "@/api/generated/types.gen";
export function resourceLabel(item: InventoryResource): string {
  return `${item.displayName}${item.kind === "pve_guest" ? ` · ${item.attributes.guestType.toUpperCase()} ${item.attributes.vmid} · ${item.attributes.nodeName ?? "Node unbekannt"}` : ""} · ${item.connectionName} · ${item.id}`;
}
export function resourcePages(query: Omit<ResourceQuery, "limit" | "cursor">) {
  return async (cursor?: string) => {
    const result = await inventoryApi.getResources({
      ...query,
      limit: 100,
      ...(cursor ? { cursor } : {}),
    });
    return {
      ...result,
      items: result.items.map((item) => ({
        id: item.id,
        label: resourceLabel(item),
      })),
    };
  };
}
export async function connectionPages(cursor?: string) {
  if (!useAuthStore().hasPermission("backup_configuration.manage")) {
    const pbs = cursor?.startsWith("pbs:") ?? false;
    const sourceCursor = cursor?.slice(4);
    const result = await inventoryApi.getResources({
      kind: pbs ? "pbs_server" : "pve_cluster",
      limit: 100,
      ...(sourceCursor ? { cursor: sourceCursor } : {}),
    });
    const nextCursor = result.page.nextCursor
      ? `${pbs ? "pbs" : "pve"}:${result.page.nextCursor}`
      : pbs
        ? null
        : "pbs:";
    return {
      items: result.items.map((item) => ({
        id: item.connectionId,
        label: `${item.connectionName} · ${item.connectionId}`,
      })),
      page: { ...result.page, nextCursor, hasMore: nextCursor !== null },
    };
  }
  const result = await connectionApi.list({
    limit: 100,
    ...(cursor ? { cursor } : {}),
  });
  return {
    ...result,
    items: result.items.map((item) => ({
      id: item.id,
      label: `${item.displayName} · ${item.id}`,
    })),
  };
}
export async function policyPages(cursor?: string) {
  const result = await policyApi.getPolicies({
    limit: 100,
    ...(cursor ? { cursor } : {}),
  });
  return {
    ...result,
    items: result.items.map((item) => ({
      id: item.id,
      label: `${item.displayName} · ${item.id}`,
    })),
  };
}

export async function targetPages(cursor?: string) {
  const { configurationApi } = await import("@/api/configurationApi");
  const result = await configurationApi.getBackupTargets({
    limit: 100,
    ...(cursor ? { cursor } : {}),
  });
  return {
    ...result,
    items: result.items.map((item) => ({
      id: item.id,
      label: `${item.displayName} · ${item.id}`,
    })),
  };
}

/** Namespace roots belong to datastores; nested namespaces belong to namespaces. */
export function namespaceParentPages(
  query: Pick<ResourceQuery, "connectionId" | "inventoryState">,
) {
  return async (cursor?: string) => {
    const nested = cursor?.startsWith("namespace:") ?? false;
    const sourceCursor = cursor?.slice(cursor.indexOf(":") + 1);
    const result = await resourcePages({
      ...query,
      kind: nested ? "pbs_namespace" : "pbs_datastore",
    })(sourceCursor || undefined);
    const nextCursor = result.page.nextCursor
      ? `${nested ? "namespace" : "datastore"}:${result.page.nextCursor}`
      : nested
        ? null
        : "namespace:";
    return {
      items: result.items.map((item) => ({
        ...item,
        label: `${nested ? "Namespace" : "Datastore"} · ${item.label}`,
      })),
      page: { ...result.page, nextCursor, hasMore: nextCursor !== null },
    };
  };
}
