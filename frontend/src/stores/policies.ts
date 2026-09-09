import { computed, ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import type {
  ConfiguredPolicy,
  PolicySelectionEntry,
  PolicyStatus,
} from "@/api/generated/types.gen";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";
import { policyApi, type PolicyApi, type PolicyQuery } from "@/api/policyApi";

const defaultPage = (): CursorPageMetadata => ({
  limit: 20,
  count: 0,
  hasMore: false,
  nextCursor: null,
});
const OPERATION_PAGE_LIMIT = 100;
const MAX_OPERATION_PAGES = 10_000;
const SELECTION_EDIT_PAGE_LIMIT = 100;
const MAX_SELECTION_EDIT_PAGES = 10_000;

function httpStatus(error: unknown): number | null {
  if (typeof error !== "object" || error === null) return null;
  const value = (error as Record<string, unknown>).httpStatus;
  return typeof value === "number" ? value : null;
}

export function policyErrorMessage(error: unknown): string {
  if (httpStatus(error) === 400) {
    return "Die Policy-Filter oder der Seiten-Cursor sind ungültig.";
  }
  if (httpStatus(error) === 503) {
    return "Die Policy-Projektion ist vorübergehend nicht verfügbar.";
  }
  return apiErrorMessage(error);
}

export const usePoliciesStore = defineStore("policies", () => {
  const search = ref("");
  const status = ref<PolicyStatus | null>(null);
  const page = ref(defaultPage());
  const items = ref<ConfiguredPolicy[]>([]);
  const operationItems = ref<ConfiguredPolicy[]>([]);
  const loading = ref(false);
  const operationLoading = ref(false);
  const error = ref<string | null>(null);
  const operationError = ref<string | null>(null);
  const selectedPolicyId = ref<string | null>(null);
  const selectionPage = ref(defaultPage());
  const selectionItems = ref<PolicySelectionEntry[]>([]);
  const selectionLoading = ref(false);
  const selectionError = ref<string | null>(null);
  let requestId = 0;
  let operationRequestId = 0;
  let selectionRequestId = 0;

  const empty = computed(() => !loading.value && items.value.length === 0);
  const selectionEmpty = computed(
    () => !selectionLoading.value && selectionItems.value.length === 0,
  );

  function query(cursor?: string): PolicyQuery {
    return {
      limit: page.value.limit,
      ...(cursor === undefined ? {} : { cursor }),
      ...(search.value === "" ? {} : { search: search.value }),
      ...(status.value === null ? {} : { status: status.value }),
    };
  }

  async function load(api: PolicyApi = policyApi, append = false) {
    const currentRequest = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const cursor = append ? pageContinuation(page.value) : undefined;
      const result = await api.getPolicies(query(cursor));
      if (currentRequest !== requestId) return;
      pageContinuation(result.page, cursor);
      items.value = append ? [...items.value, ...result.items] : result.items;
      page.value = result.page;
    } catch (caught) {
      if (currentRequest === requestId) {
        items.value = [];
        page.value = { ...defaultPage(), limit: page.value.limit };
        error.value = policyErrorMessage(caught);
      }
    } finally {
      if (currentRequest === requestId) loading.value = false;
    }
  }

  async function loadMore(api: PolicyApi = policyApi) {
    if (loading.value || !page.value.hasMore) return;
    await load(api, true);
  }

  async function loadAllEnabledForOperations(
    api: PolicyApi = policyApi,
  ): Promise<void> {
    const currentRequest = ++operationRequestId;
    operationLoading.value = true;
    operationError.value = null;
    try {
      const collected: ConfiguredPolicy[] = [];
      let cursor: string | undefined;
      for (let pageNumber = 0; pageNumber < MAX_OPERATION_PAGES; pageNumber++) {
        const result = await api.getPolicies({
          limit: OPERATION_PAGE_LIMIT,
          status: "enabled",
          ...(cursor === undefined ? {} : { cursor }),
        });
        if (currentRequest !== operationRequestId) return;
        collected.push(...result.items);
        const next = pageContinuation(result.page, cursor);
        if (next === undefined) {
          operationItems.value = collected;
          return;
        }
        cursor = next;
      }
      throw new Error(
        "Die Policy-Pagination überschreitet das sichere Seitenlimit.",
      );
    } catch (caught) {
      if (currentRequest === operationRequestId) {
        operationItems.value = [];
        operationError.value = policyErrorMessage(caught);
      }
    } finally {
      if (currentRequest === operationRequestId) operationLoading.value = false;
    }
  }

  async function selectPolicy(policyId: string, api: PolicyApi = policyApi) {
    const changed = selectedPolicyId.value !== policyId;
    selectedPolicyId.value = policyId;
    if (changed) {
      selectionItems.value = [];
      selectionPage.value = defaultPage();
    }
    await loadSelection(api);
  }

  async function selectPolicyForEditing(
    policyId: string,
    api: PolicyApi = policyApi,
  ): Promise<void> {
    selectedPolicyId.value = policyId;
    selectionItems.value = [];
    selectionPage.value = defaultPage();
    const currentRequest = ++selectionRequestId;
    selectionLoading.value = true;
    selectionError.value = null;
    try {
      const collected: PolicySelectionEntry[] = [];
      let cursor: string | undefined;
      for (
        let pageNumber = 0;
        pageNumber < MAX_SELECTION_EDIT_PAGES;
        pageNumber++
      ) {
        const result = await api.getSelection(policyId, {
          limit: SELECTION_EDIT_PAGE_LIMIT,
          ...(cursor === undefined ? {} : { cursor }),
        });
        if (
          currentRequest !== selectionRequestId ||
          policyId !== selectedPolicyId.value
        )
          return;
        collected.push(...result.items);
        const next = pageContinuation(result.page, cursor);
        if (next === undefined) {
          selectionItems.value = collected;
          selectionPage.value = {
            limit: SELECTION_EDIT_PAGE_LIMIT,
            count: collected.length,
            hasMore: false,
            nextCursor: null,
          };
          return;
        }
        cursor = next;
      }
      throw new Error(
        "Die Auswahlregel-Pagination überschreitet das sichere Seitenlimit.",
      );
    } catch (caught) {
      if (currentRequest === selectionRequestId) {
        selectionItems.value = [];
        selectionPage.value = defaultPage();
        selectionError.value = policyErrorMessage(caught);
      }
    } finally {
      if (currentRequest === selectionRequestId) selectionLoading.value = false;
    }
  }

  async function loadSelection(api: PolicyApi = policyApi, append = false) {
    if (selectedPolicyId.value === null) return;
    const policyId = selectedPolicyId.value;
    const currentRequest = ++selectionRequestId;
    selectionLoading.value = true;
    selectionError.value = null;
    try {
      const cursor = append ? pageContinuation(selectionPage.value) : undefined;
      const result = await api.getSelection(policyId, {
        limit: selectionPage.value.limit,
        ...(cursor === undefined ? {} : { cursor }),
      });
      if (
        currentRequest !== selectionRequestId ||
        policyId !== selectedPolicyId.value
      )
        return;
      pageContinuation(result.page, cursor);
      selectionItems.value = append
        ? [...selectionItems.value, ...result.items]
        : result.items;
      selectionPage.value = result.page;
    } catch (caught) {
      if (currentRequest === selectionRequestId) {
        selectionItems.value = [];
        selectionPage.value = {
          ...defaultPage(),
          limit: selectionPage.value.limit,
        };
        selectionError.value = policyErrorMessage(caught);
      }
    } finally {
      if (currentRequest === selectionRequestId) selectionLoading.value = false;
    }
  }

  async function loadMoreSelection(api: PolicyApi = policyApi) {
    if (selectionLoading.value || !selectionPage.value.hasMore) return;
    await loadSelection(api, true);
  }

  function setSearch(value: string) {
    search.value = value.trim();
    page.value = { ...defaultPage(), limit: page.value.limit };
  }

  function setStatus(value: PolicyStatus | null) {
    status.value = value;
    page.value = { ...defaultPage(), limit: page.value.limit };
  }

  return {
    search,
    status,
    page,
    items,
    operationItems,
    loading,
    operationLoading,
    error,
    operationError,
    empty,
    selectedPolicyId,
    selectionPage,
    selectionItems,
    selectionLoading,
    selectionError,
    selectionEmpty,
    query,
    load,
    loadMore,
    loadAllEnabledForOperations,
    selectPolicy,
    selectPolicyForEditing,
    loadSelection,
    loadMoreSelection,
    setSearch,
    setStatus,
  };
});
