import { afterEach, describe, expect, it, vi } from "vitest";

import { UUID } from "@/test/fixtures";
import { createConfigurationApi } from "./configurationApi";

const emptyPage = {
  items: [],
  page: { limit: 20, count: 0, hasMore: false, nextCursor: null },
};

describe("generierter Configuration-API-Client", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("verwendet ausschließlich den versionierten GET-Vertrag und alle Filter", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify(emptyPage), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      createConfigurationApi(
        "https://hoddmimir.example.test",
      ).getBackupTargetCandidates({
        limit: 20,
        cursor: "opaque-cursor",
        connectionId: UUID,
        clusterId: UUID,
      }),
    ).resolves.toEqual(emptyPage);

    const request = fetchMock.mock.calls[0]?.[0] as Request;
    const url = new URL(request.url);
    expect(request.method).toBe("GET");
    expect(url.pathname).toBe("/api/v1/backup-target-candidates");
    expect(Object.fromEntries(url.searchParams)).toEqual({
      limit: "20",
      cursor: "opaque-cursor",
      connectionId: UUID,
      clusterId: UUID,
    });
  });

  it.each([400, 503] as const)(
    "erhält den HTTP-Status %s für die fail-closed Fehlerdarstellung",
    async (status) => {
      vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue(
          new Response(
            JSON.stringify({
              error: { code: "unavailable", message: "hidden" },
            }),
            { status, headers: { "Content-Type": "application/json" } },
          ),
        ),
      );

      await expect(
        createConfigurationApi(
          "https://hoddmimir.example.test",
        ).getBackupTargetCandidates({ limit: 20 }),
      ).rejects.toMatchObject({ httpStatus: status });
    },
  );
});
