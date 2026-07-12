import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import { listBackupTargetCandidates } from "@/api/generated/sdk.gen";
import type {
  BackupTargetCandidate,
  BackupTargetCandidatePage,
} from "@/api/generated/types.gen";
import type { CursorPage, CursorPageQuery } from "@/api/pagination";

export interface BackupTargetCandidateQuery extends CursorPageQuery {
  connectionId?: string;
  clusterId?: string;
}

export interface ConfigurationApi {
  getBackupTargetCandidates(
    query: BackupTargetCandidateQuery,
  ): Promise<CursorPage<BackupTargetCandidate>>;
}

export function createConfigurationApi(baseUrl = ""): ConfigurationApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );

  return {
    async getBackupTargetCandidates(query) {
      const result = (
        await listBackupTargetCandidates({
          client,
          query: {
            limit: query.limit,
            ...(query.cursor === undefined ? {} : { cursor: query.cursor }),
            ...(query.connectionId === undefined
              ? {}
              : { connectionId: query.connectionId }),
            ...(query.clusterId === undefined
              ? {}
              : { clusterId: query.clusterId }),
          },
          throwOnError: true,
        })
      ).data satisfies BackupTargetCandidatePage;
      return result;
    },
  };
}

export const configurationApi = createConfigurationApi();
