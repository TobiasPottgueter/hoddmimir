import { getPbsTask, listPbsTasks } from "@/api/generated/sdk.gen";
import { createClient, createConfig } from "@/api/generated/client";

const client = createClient(createConfig({ baseUrl: "" }));
export async function loadPbsTasks(offset: number, connectionId?: string) {
  return (
    await listPbsTasks({
      client,
      query: { offset, ...(connectionId ? { connectionId } : {}) },
      throwOnError: true,
    })
  ).data;
}
export async function loadPbsTask(id: string) {
  return (await getPbsTask({ client, path: { id }, throwOnError: true })).data;
}
