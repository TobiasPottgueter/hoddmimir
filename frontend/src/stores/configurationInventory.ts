import { ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import { inventoryApi, type InventoryApi } from "@/api/inventoryApi";
import { pageContinuation } from "@/api/pagination";
import type {
  InventoryResource,
  PveClusterResource,
  PveGuestResource,
  PveNodeResource,
} from "@/api/generated/types.gen";

const BOUNDED_LIMIT = 100;
const MAX_CONFIGURATION_PAGES = 10_000;

async function loadAllResources(
  api: InventoryApi,
  query: Omit<Parameters<InventoryApi["getResources"]>[0], "cursor">,
  isCurrent: () => boolean,
): Promise<InventoryResource[] | null> {
  const items: InventoryResource[] = [];
  let cursor: string | undefined;
  for (let pageNumber = 0; pageNumber < MAX_CONFIGURATION_PAGES; pageNumber++) {
    const result = await api.getResources({
      ...query,
      ...(cursor === undefined ? {} : { cursor }),
    });
    if (!isCurrent()) return null;
    items.push(...result.items);
    const next = pageContinuation(result.page, cursor);
    if (next === undefined) return items;
    cursor = next;
  }
  throw new Error(
    "Die Inventar-Pagination überschreitet das sichere Seitenlimit.",
  );
}

export const useConfigurationInventoryStore = defineStore(
  "configuration-inventory",
  () => {
    const clusters = ref<PveClusterResource[]>([]);
    const nodes = ref<PveNodeResource[]>([]);
    const guests = ref<PveGuestResource[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let requestId = 0;

    async function loadClusters(
      api: InventoryApi = inventoryApi,
    ): Promise<void> {
      const current = ++requestId;
      loading.value = true;
      error.value = null;
      try {
        const items = await loadAllResources(
          api,
          {
            kind: "pve_cluster",
            inventoryState: "active",
            limit: BOUNDED_LIMIT,
          },
          () => current === requestId,
        );
        if (items === null) return;
        clusters.value = items.filter(
          (item): item is PveClusterResource => item.kind === "pve_cluster",
        );
      } catch (caught) {
        if (current === requestId) {
          clusters.value = [];
          error.value = apiErrorMessage(caught);
        }
      } finally {
        if (current === requestId) loading.value = false;
      }
    }

    async function loadHierarchy(
      connectionId: string,
      clusterId: string,
      api: InventoryApi = inventoryApi,
    ): Promise<void> {
      const current = ++requestId;
      loading.value = true;
      error.value = null;
      try {
        const [nodeItems, qemuItems, lxcItems] = await Promise.all([
          loadAllResources(
            api,
            {
              kind: "pve_node",
              connectionId,
              parentId: clusterId,
              inventoryState: "active",
              limit: BOUNDED_LIMIT,
            },
            () => current === requestId,
          ),
          loadAllResources(
            api,
            {
              kind: "pve_guest",
              connectionId,
              parentId: clusterId,
              inventoryState: "active",
              guestType: "qemu",
              limit: BOUNDED_LIMIT,
            },
            () => current === requestId,
          ),
          loadAllResources(
            api,
            {
              kind: "pve_guest",
              connectionId,
              parentId: clusterId,
              inventoryState: "active",
              guestType: "lxc",
              limit: BOUNDED_LIMIT,
            },
            () => current === requestId,
          ),
        ]);
        if (nodeItems === null || qemuItems === null || lxcItems === null)
          return;
        nodes.value = nodeItems.filter(
          (item): item is PveNodeResource => item.kind === "pve_node",
        );
        guests.value = [...qemuItems, ...lxcItems].filter(
          (item): item is PveGuestResource => item.kind === "pve_guest",
        );
      } catch (caught) {
        if (current === requestId) {
          nodes.value = [];
          guests.value = [];
          error.value = apiErrorMessage(caught);
        }
      } finally {
        if (current === requestId) loading.value = false;
      }
    }

    return {
      clusters,
      nodes,
      guests,
      loading,
      error,
      loadClusters,
      loadHierarchy,
    };
  },
);
