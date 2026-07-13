import { computed, ref } from "vue";
import { defineStore } from "pinia";

import {
  configurationApi,
  type ConfigurationApi,
  type ConfiguredBackupTargetQuery,
} from "@/api/configurationApi";
import type { ConfiguredBackupTarget } from "@/api/generated/types.gen";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";
import { configurationErrorMessage } from "@/stores/backupTargets";

const DEFAULT_PAGE: CursorPageMetadata = {
  limit: 20,
  count: 0,
  hasMore: false,
  nextCursor: null,
};
const CONFIGURATION_PAGE_LIMIT = 100;
const MAX_CONFIGURATION_PAGES = 10_000;

export const useConfiguredBackupTargetsStore = defineStore(
  "configured-backup-targets",
  () => {
    const search = ref("");
    const enabled = ref<boolean | null>(null);
    const page = ref<CursorPageMetadata>({ ...DEFAULT_PAGE });
    const items = ref<ConfiguredBackupTarget[]>([]);
    const configurationItems = ref<ConfiguredBackupTarget[]>([]);
    const loading = ref(false);
    const configurationLoading = ref(false);
    const error = ref<string | null>(null);
    const configurationError = ref<string | null>(null);
    let requestId = 0;
    let configurationRequestId = 0;

    const empty = computed(() => !loading.value && items.value.length === 0);

    function query(cursor?: string): ConfiguredBackupTargetQuery {
      return {
        limit: page.value.limit,
        ...(cursor === undefined ? {} : { cursor }),
        ...(search.value === "" ? {} : { search: search.value }),
        ...(enabled.value === null ? {} : { enabled: enabled.value }),
      };
    }

    async function load(
      api: ConfigurationApi = configurationApi,
      append = false,
    ): Promise<void> {
      const currentRequest = ++requestId;
      loading.value = true;
      error.value = null;
      try {
        const cursor = append ? pageContinuation(page.value) : undefined;
        const result = await api.getBackupTargets(query(cursor));
        if (currentRequest !== requestId) return;
        pageContinuation(result.page, cursor);
        items.value = append ? [...items.value, ...result.items] : result.items;
        page.value = result.page;
      } catch (caught) {
        if (currentRequest === requestId) {
          items.value = [];
          page.value = { ...DEFAULT_PAGE, limit: page.value.limit };
          error.value = configurationErrorMessage(caught);
        }
      } finally {
        if (currentRequest === requestId) loading.value = false;
      }
    }

    async function loadMore(
      api: ConfigurationApi = configurationApi,
    ): Promise<void> {
      if (loading.value || !page.value.hasMore) return;
      await load(api, true);
    }

    async function loadAllForConfiguration(
      api: ConfigurationApi = configurationApi,
    ): Promise<void> {
      const currentRequest = ++configurationRequestId;
      configurationLoading.value = true;
      configurationError.value = null;
      try {
        const collected: ConfiguredBackupTarget[] = [];
        let cursor: string | undefined;
        for (
          let pageNumber = 0;
          pageNumber < MAX_CONFIGURATION_PAGES;
          pageNumber++
        ) {
          const result = await api.getBackupTargets({
            limit: CONFIGURATION_PAGE_LIMIT,
            ...(cursor === undefined ? {} : { cursor }),
          });
          if (currentRequest !== configurationRequestId) return;
          collected.push(...result.items);
          const next = pageContinuation(result.page, cursor);
          if (next === undefined) {
            configurationItems.value = collected;
            return;
          }
          cursor = next;
        }
        throw new Error(
          "Die Backupziel-Pagination überschreitet das sichere Seitenlimit.",
        );
      } catch (caught) {
        if (currentRequest === configurationRequestId) {
          configurationItems.value = [];
          configurationError.value = configurationErrorMessage(caught);
        }
      } finally {
        if (currentRequest === configurationRequestId)
          configurationLoading.value = false;
      }
    }

    function resetCursor(): void {
      page.value = { ...DEFAULT_PAGE, limit: page.value.limit };
    }

    function setSearch(value: string): void {
      search.value = value.trim();
      resetCursor();
    }

    function setEnabled(value: boolean | null): void {
      enabled.value = value;
      resetCursor();
    }

    return {
      search,
      enabled,
      page,
      items,
      configurationItems,
      loading,
      configurationLoading,
      error,
      configurationError,
      empty,
      query,
      load,
      loadMore,
      loadAllForConfiguration,
      setSearch,
      setEnabled,
    };
  },
);
