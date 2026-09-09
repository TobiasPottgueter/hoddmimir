import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import { listPolicies, listPolicySelection } from "@/api/generated/sdk.gen";
import type {
  ConfiguredPolicy,
  PolicyPage,
  PolicySelectionEntry,
  PolicySelectionPage,
  PolicyStatus,
} from "@/api/generated/types.gen";
import type { CursorPage, CursorPageQuery } from "@/api/pagination";

export interface PolicyQuery extends CursorPageQuery {
  search?: string;
  status?: PolicyStatus;
}

export interface PolicyApi {
  getPolicies(query: PolicyQuery): Promise<CursorPage<ConfiguredPolicy>>;
  getSelection(
    policyId: string,
    query: CursorPageQuery,
  ): Promise<CursorPage<PolicySelectionEntry>>;
}

export function createPolicyApi(baseUrl = ""): PolicyApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );

  return {
    async getPolicies(query) {
      return (
        await listPolicies({
          client,
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
            ...(query.search === undefined ? {} : { search: query.search }),
            ...(query.status === undefined ? {} : { status: query.status }),
          },
          throwOnError: true,
        })
      ).data satisfies PolicyPage;
    },
    async getSelection(policyId, query) {
      return (
        await listPolicySelection({
          client,
          path: { id: policyId },
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
          },
          throwOnError: true,
        })
      ).data satisfies PolicySelectionPage;
    },
  };
}

export const policyApi = createPolicyApi();
