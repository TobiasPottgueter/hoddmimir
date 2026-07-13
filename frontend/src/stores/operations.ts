import { computed, ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import { inventoryApi, type InventoryApi } from "@/api/inventoryApi";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";
import type {
  CollectorRun,
  CollectorScope,
  CollectorStatus,
} from "@/api/generated/types.gen";

const emptyPage = (limit: number): CursorPageMetadata => ({
  limit,
  count: 0,
  hasMore: false,
  nextCursor: null,
});
export const useOperationsStore = defineStore("operations", () => {
  const status = ref<CollectorStatus | null>(null);
  const runs = ref<CollectorRun[]>([]);
  const runsPage = ref(emptyPage(25));
  const scopes = ref<CollectorScope[]>([]);
  const scopesPage = ref(emptyPage(50));
  const selectedRunId = ref<string | null>(null);
  const loading = ref(false);
  const scopesLoading = ref(false);
  const error = ref<string | null>(null);
  const scopesError = ref<string | null>(null);
  let requestId = 0;
  let scopeRequestId = 0;
  const incompleteScopeCount = computed(
    () => scopes.value.filter((scope) => scope.status !== "complete").length,
  );
  async function load(
    api: InventoryApi = inventoryApi,
    append = false,
  ): Promise<void> {
    const currentRequest = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const cursor = append ? pageContinuation(runsPage.value) : undefined;
      const [statusResult, runsResult] = await Promise.all([
        api.getCollectorStatus(),
        api.getCollectorRuns({
          limit: runsPage.value.limit,
          ...(cursor === undefined ? {} : { cursor }),
        }),
      ]);
      if (currentRequest !== requestId) return;
      pageContinuation(runsResult.page, cursor);
      status.value = statusResult;
      runs.value = append
        ? [...runs.value, ...runsResult.items]
        : runsResult.items;
      runsPage.value = runsResult.page;
    } catch (caught) {
      if (currentRequest === requestId) error.value = apiErrorMessage(caught);
    } finally {
      if (currentRequest === requestId) loading.value = false;
    }
  }
  async function loadMoreRuns(api: InventoryApi = inventoryApi): Promise<void> {
    if (loading.value || !runsPage.value.hasMore) return;
    await load(api, true);
  }
  async function loadScopes(
    runId: string,
    append: boolean,
    api: InventoryApi,
  ): Promise<void> {
    const currentRequest = ++scopeRequestId;
    scopesLoading.value = true;
    scopesError.value = null;
    if (!append) {
      scopes.value = [];
      scopesPage.value = emptyPage(scopesPage.value.limit);
    }
    try {
      const cursor = append ? pageContinuation(scopesPage.value) : undefined;
      const result = await api.getCollectorScopes({
        runId,
        limit: scopesPage.value.limit,
        ...(cursor === undefined ? {} : { cursor }),
      });
      if (currentRequest !== scopeRequestId) return;
      pageContinuation(result.page, cursor);
      scopes.value = append ? [...scopes.value, ...result.items] : result.items;
      scopesPage.value = result.page;
    } catch (caught) {
      if (currentRequest === scopeRequestId)
        scopesError.value = apiErrorMessage(caught);
    } finally {
      if (currentRequest === scopeRequestId) scopesLoading.value = false;
    }
  }
  async function selectRun(
    runId: string,
    api: InventoryApi = inventoryApi,
  ): Promise<void> {
    selectedRunId.value = runId;
    await loadScopes(runId, false, api);
  }
  async function loadMoreScopes(
    api: InventoryApi = inventoryApi,
  ): Promise<void> {
    if (
      scopesLoading.value ||
      !scopesPage.value.hasMore ||
      selectedRunId.value === null
    )
      return;
    await loadScopes(selectedRunId.value, true, api);
  }
  return {
    status,
    runs,
    runsPage,
    scopes,
    scopesPage,
    selectedRunId,
    loading,
    scopesLoading,
    error,
    scopesError,
    incompleteScopeCount,
    load,
    loadMoreRuns,
    selectRun,
    loadMoreScopes,
  };
});
