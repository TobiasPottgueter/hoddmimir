import { computed, ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import { inventoryApi, type InventoryApi } from "@/api/inventoryApi";
import { pageContinuation } from "@/api/pagination";
import type {
  InventoryOverview,
  InventoryResource,
  InventoryResourceKind,
} from "@/api/generated/types.gen";

export const useSystemsStore = defineStore("systems", () => {
  const overview = ref<InventoryOverview | null>(null);
  const pveClusters = ref<InventoryResource[]>([]);
  const pbsServers = ref<InventoryResource[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  let requestId = 0;

  const empty = computed(
    () => pveClusters.value.length === 0 && pbsServers.value.length === 0,
  );

  async function allResources(
    kind: InventoryResourceKind,
    currentRequest: number,
    api: InventoryApi,
  ): Promise<InventoryResource[]> {
    const resources: InventoryResource[] = [];
    let cursor: string | undefined;
    do {
      const result = await api.getResources({
        kind,
        inventoryState: "active",
        limit: 100,
        ...(cursor === undefined ? {} : { cursor }),
      });
      if (currentRequest !== requestId) return [];
      resources.push(...result.items);
      cursor = pageContinuation(result.page, cursor);
    } while (cursor !== undefined);
    return resources;
  }

  async function load(api: InventoryApi = inventoryApi): Promise<void> {
    const currentRequest = ++requestId;
    loading.value = true;
    error.value = null;

    try {
      const [overviewResult, clusters, servers] = await Promise.all([
        api.getOverview(),
        allResources("pve_cluster", currentRequest, api),
        allResources("pbs_server", currentRequest, api),
      ]);
      if (currentRequest !== requestId) return;

      overview.value = overviewResult;
      pveClusters.value = clusters;
      pbsServers.value = servers;
    } catch (caught) {
      if (currentRequest === requestId) error.value = apiErrorMessage(caught);
    } finally {
      if (currentRequest === requestId) loading.value = false;
    }
  }

  return { overview, pveClusters, pbsServers, loading, error, empty, load };
});
