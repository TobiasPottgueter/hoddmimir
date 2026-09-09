import { getQueueHistory } from "@/api/generated/sdk.gen";
import { createClient, createConfig } from "@/api/generated/client";

const client = createClient(createConfig({ baseUrl: "" }));
export async function loadQueueHistory(
  hours: 24 | 168 | 720,
  targetId?: string,
) {
  return (
    await getQueueHistory({
      client,
      query: { hours, ...(targetId ? { targetId } : {}) },
      throwOnError: true,
    })
  ).data;
}
