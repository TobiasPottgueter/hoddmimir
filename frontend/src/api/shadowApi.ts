import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  getShadowDecision,
  listShadowDecisions,
  listShadowEvaluations,
} from "@/api/generated/sdk.gen";
import type {
  ShadowDecision,
  ShadowDecisionDetail,
  ShadowDecisionPage,
  ShadowEvaluation,
  ShadowEvaluationPage,
} from "@/api/generated/types.gen";
import type { CursorPage } from "@/api/pagination";

export type {
  ShadowDecision,
  ShadowDecisionDetail,
  ShadowEvaluation,
  ShadowGate,
  ShadowGateCode,
  ShadowGateDetailCode,
  ShadowGateScope,
  ShadowOutcome,
  ShadowReason,
} from "@/api/generated/types.gen";

export interface ShadowDecisionQuery {
  limit: number;
  cursor?: string;
  outcome?: "eligible" | "blocked" | "not_due" | "deduplicated";
  reason?: "manual" | "never_backed_up" | "max_age" | "bytes_written";
  policyId?: string;
  targetId?: string;
  guestId?: string;
}

export interface ShadowApi {
  evaluations(
    limit: number,
    cursor?: string,
  ): Promise<CursorPage<ShadowEvaluation>>;
  decisions(query: ShadowDecisionQuery): Promise<CursorPage<ShadowDecision>>;
  decision(id: string): Promise<ShadowDecisionDetail>;
}

export function createShadowApi(baseUrl = ""): ShadowApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );

  return {
    async evaluations(limit, cursor) {
      return (
        await listShadowEvaluations({
          client,
          query: {
            limit,
            ...(cursor === undefined ? {} : { cursor }),
          },
          throwOnError: true,
        })
      ).data satisfies ShadowEvaluationPage;
    },
    async decisions(query) {
      return (
        await listShadowDecisions({
          client,
          query,
          throwOnError: true,
        })
      ).data satisfies ShadowDecisionPage;
    },
    async decision(id) {
      return (
        await getShadowDecision({
          client,
          path: { id },
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const shadowApi = createShadowApi();
