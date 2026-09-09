import { computed, ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import {
  inventoryApi,
  type InventoryApi,
  type ResourceQuery,
} from "@/api/inventoryApi";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";
import type {
  InventoryResource,
  InventoryResourceKind,
  InventoryState,
} from "@/api/generated/types.gen";

const DEFAULT_PAGE: CursorPageMetadata = {
  limit: 25,
  count: 0,
  hasMore: false,
  nextCursor: null,
};

export const useInventoryStore = defineStore("inventory", () => {
  const kind = ref<InventoryResourceKind>("pve_guest");
  const inventoryState = ref<InventoryState>("active");
  const guestType = ref<"all" | "qemu" | "lxc">("all");
  const connectionId = ref("");
  const parentId = ref("");
  const page = ref<CursorPageMetadata>({ ...DEFAULT_PAGE });
  const items = ref<InventoryResource[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  let requestId = 0;

  const empty = computed(() => !loading.value && items.value.length === 0);

  function query(cursor?: string): ResourceQuery {
    const value: ResourceQuery = {
      kind: kind.value,
      limit: page.value.limit,
      inventoryState: inventoryState.value,
    };
    if (cursor !== undefined) value.cursor = cursor;
    if (connectionId.value !== "") value.connectionId = connectionId.value;
    if (
      parentId.value !== "" &&
      !["pve_cluster", "pbs_server"].includes(kind.value)
    ) {
      value.parentId = parentId.value;
    }
    if (kind.value === "pve_guest" && guestType.value !== "all") {
      value.guestType = guestType.value;
    }
    return value;
  }

  async function load(
    api: InventoryApi = inventoryApi,
    append = false,
  ): Promise<void> {
    const currentRequest = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const cursor = append ? pageContinuation(page.value) : undefined;
      const result = await api.getResources(query(cursor));
      if (currentRequest !== requestId) return;
      pageContinuation(result.page, cursor);
      items.value = append ? [...items.value, ...result.items] : result.items;
      page.value = result.page;
    } catch (caught) {
      if (currentRequest === requestId) {
        items.value = [];
        error.value = apiErrorMessage(caught);
      }
    } finally {
      if (currentRequest === requestId) loading.value = false;
    }
  }

  async function loadMore(api: InventoryApi = inventoryApi): Promise<void> {
    if (loading.value || !page.value.hasMore) return;
    await load(api, true);
  }

  function resetPage(): void {
    page.value = { ...DEFAULT_PAGE, limit: page.value.limit };
  }

  function setKind(value: InventoryResourceKind): void {
    kind.value = value;
    if (value !== "pve_guest") guestType.value = "all";
    if (["pve_cluster", "pbs_server"].includes(value)) parentId.value = "";
    resetPage();
  }

  function setInventoryState(value: InventoryState): void {
    inventoryState.value = value;
    resetPage();
  }

  function setGuestType(value: "all" | "qemu" | "lxc"): void {
    guestType.value = value;
    resetPage();
  }

  function setConnectionId(value: string): void {
    connectionId.value = value.trim();
    resetPage();
  }

  function setParentId(value: string): void {
    parentId.value = value.trim();
    resetPage();
  }

  return {
    kind,
    inventoryState,
    guestType,
    connectionId,
    parentId,
    page,
    items,
    loading,
    error,
    empty,
    load,
    loadMore,
    query,
    setKind,
    setInventoryState,
    setGuestType,
    setConnectionId,
    setParentId,
  };
});
