import { computed, ref } from "vue";
import { defineStore } from "pinia";

import {
  configurationApi,
  type BackupTargetCandidateQuery,
  type ConfigurationApi,
} from "@/api/configurationApi";
import { apiErrorMessage } from "@/api/errors";
import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";

const DEFAULT_PAGE: CursorPageMetadata = {
  limit: 20,
  count: 0,
  hasMore: false,
  nextCursor: null,
};

function statusOf(error: unknown): number | null {
  if (typeof error !== "object" || error === null) return null;
  const status = (error as Record<string, unknown>).httpStatus;
  return typeof status === "number" ? status : null;
}

export function configurationErrorMessage(error: unknown): string {
  if (statusOf(error) === 400) {
    return "Die Filter oder der Seiten-Cursor sind ungültig. Bitte wende die Filter erneut an.";
  }
  if (statusOf(error) === 503) {
    return "Die Backupziel-Projektion ist vorübergehend nicht verfügbar.";
  }
  return apiErrorMessage(error);
}

export const useBackupTargetsStore = defineStore("backup-targets", () => {
  const connectionId = ref("");
  const clusterId = ref("");
  const page = ref<CursorPageMetadata>({ ...DEFAULT_PAGE });
  const items = ref<BackupTargetCandidate[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  let requestId = 0;

  const empty = computed(() => !loading.value && items.value.length === 0);

  function query(cursor?: string): BackupTargetCandidateQuery {
    return {
      limit: page.value.limit,
      ...(cursor === undefined ? {} : { cursor }),
      ...(connectionId.value === ""
        ? {}
        : { connectionId: connectionId.value }),
      ...(clusterId.value === "" ? {} : { clusterId: clusterId.value }),
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
      const result = await api.getBackupTargetCandidates(query(cursor));
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

  function resetCursor(): void {
    page.value = { ...DEFAULT_PAGE, limit: page.value.limit };
  }

  function setConnectionId(value: string): void {
    connectionId.value = value.trim();
    resetCursor();
  }

  function setClusterId(value: string): void {
    clusterId.value = value.trim();
    resetCursor();
  }

  return {
    connectionId,
    clusterId,
    page,
    items,
    loading,
    error,
    empty,
    query,
    load,
    loadMore,
    setConnectionId,
    setClusterId,
  };
});
