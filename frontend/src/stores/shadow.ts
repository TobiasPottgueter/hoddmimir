import { computed, ref } from "vue";
import { defineStore } from "pinia";

import { apiErrorMessage } from "@/api/errors";
import {
  shadowApi,
  type ShadowApi,
  type ShadowDecision,
  type ShadowDecisionDetail,
  type ShadowDecisionQuery,
  type ShadowEvaluation,
} from "@/api/shadowApi";
import { pageContinuation, type CursorPageMetadata } from "@/api/pagination";

const emptyPage = (): CursorPageMetadata => ({
  limit: 20,
  count: 0,
  hasMore: false,
  nextCursor: null,
});

export const useShadowStore = defineStore("shadow", () => {
  const evaluations = ref<ShadowEvaluation[]>([]);
  const evaluationPage = ref(emptyPage());
  const evaluationsLoading = ref(false);
  const evaluationsError = ref<string | null>(null);
  const decisions = ref<ShadowDecision[]>([]);
  const decisionFilters = ref<Omit<ShadowDecisionQuery, "limit" | "cursor">>(
    {},
  );
  const decisionPage = ref(emptyPage());
  const decisionsLoading = ref(false);
  const decisionsError = ref<string | null>(null);
  const detail = ref<ShadowDecisionDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);
  let evaluationRequest = 0;
  let decisionRequest = 0;
  let detailRequest = 0;

  const evaluationsEmpty = computed(
    () => !evaluationsLoading.value && evaluations.value.length === 0,
  );
  const decisionsEmpty = computed(
    () => !decisionsLoading.value && decisions.value.length === 0,
  );

  async function loadEvaluations(
    api: ShadowApi = shadowApi,
    append = false,
  ): Promise<void> {
    const requestId = ++evaluationRequest;
    evaluationsLoading.value = true;
    evaluationsError.value = null;
    try {
      const cursor = append
        ? pageContinuation(evaluationPage.value)
        : undefined;
      const result = await api.evaluations(evaluationPage.value.limit, cursor);
      if (requestId !== evaluationRequest) return;
      pageContinuation(result.page, cursor);
      evaluations.value = append
        ? [...evaluations.value, ...result.items]
        : result.items;
      evaluationPage.value = result.page;
    } catch (error) {
      if (requestId === evaluationRequest) {
        evaluations.value = [];
        evaluationPage.value = emptyPage();
        evaluationsError.value = apiErrorMessage(error);
      }
    } finally {
      if (requestId === evaluationRequest) evaluationsLoading.value = false;
    }
  }

  async function loadDecisions(
    api: ShadowApi = shadowApi,
    append = false,
  ): Promise<void> {
    const requestId = ++decisionRequest;
    decisionsLoading.value = true;
    decisionsError.value = null;
    try {
      const cursor = append ? pageContinuation(decisionPage.value) : undefined;
      const result = await api.decisions({
        limit: decisionPage.value.limit,
        ...decisionFilters.value,
        ...(cursor === undefined ? {} : { cursor }),
      });
      if (requestId !== decisionRequest) return;
      pageContinuation(result.page, cursor);
      decisions.value = append
        ? [...decisions.value, ...result.items]
        : result.items;
      decisionPage.value = result.page;
    } catch (error) {
      if (requestId === decisionRequest) {
        decisions.value = [];
        decisionPage.value = emptyPage();
        decisionsError.value = apiErrorMessage(error);
      }
    } finally {
      if (requestId === decisionRequest) decisionsLoading.value = false;
    }
  }

  async function selectDecision(
    id: string,
    api: ShadowApi = shadowApi,
  ): Promise<void> {
    const requestId = ++detailRequest;
    detail.value = null;
    detailLoading.value = true;
    detailError.value = null;
    try {
      const result = await api.decision(id);
      if (requestId === detailRequest) detail.value = result;
    } catch (error) {
      if (requestId === detailRequest)
        detailError.value = apiErrorMessage(error);
    } finally {
      if (requestId === detailRequest) detailLoading.value = false;
    }
  }

  async function loadMoreEvaluations(
    api: ShadowApi = shadowApi,
  ): Promise<void> {
    if (evaluationsLoading.value || !evaluationPage.value.hasMore) return;
    await loadEvaluations(api, true);
  }

  async function loadMoreDecisions(api: ShadowApi = shadowApi): Promise<void> {
    if (decisionsLoading.value || !decisionPage.value.hasMore) return;
    await loadDecisions(api, true);
  }

  async function applyDecisionFilters(
    filters: Omit<ShadowDecisionQuery, "limit" | "cursor">,
    api: ShadowApi = shadowApi,
  ): Promise<void> {
    decisionFilters.value = { ...filters };
    decisionPage.value = emptyPage();
    detail.value = null;
    await loadDecisions(api);
  }

  return {
    evaluations,
    evaluationPage,
    evaluationsLoading,
    evaluationsError,
    evaluationsEmpty,
    decisions,
    decisionFilters,
    decisionPage,
    decisionsLoading,
    decisionsError,
    decisionsEmpty,
    detail,
    detailLoading,
    detailError,
    loadEvaluations,
    loadMoreEvaluations,
    loadDecisions,
    loadMoreDecisions,
    applyDecisionFilters,
    selectDecision,
  };
});
