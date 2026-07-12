import { afterEach, describe, expect, it, vi } from "vitest";

import {
  collectorScope,
  collectorStatus,
  overview,
  resourcePage,
  UUID,
} from "@/test/fixtures";
import { createInventoryApi } from "./inventoryApi";

describe("generierter Inventory-API-Client", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("verwendet die fünf GET-Operationen des OpenAPI-Vertrags", async () => {
    const payloads = [
      overview,
      resourcePage(),
      collectorStatus,
      {
        items: [],
        page: { limit: 25, count: 0, hasMore: false, nextCursor: null },
      },
      {
        items: [collectorScope],
        page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
      },
    ];
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(payloads.shift()), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createInventoryApi("https://hoddmimir.example.test");

    expect(await api.getOverview()).toEqual(overview);
    expect(await api.getResources({ kind: "pve_guest", limit: 25 })).toEqual(
      resourcePage(),
    );
    expect(await api.getCollectorStatus()).toEqual(collectorStatus);
    expect(await api.getCollectorRuns({ limit: 25 })).toEqual({
      items: [],
      page: { limit: 25, count: 0, hasMore: false, nextCursor: null },
    });
    expect(await api.getCollectorScopes({ runId: UUID, limit: 50 })).toEqual({
      items: [collectorScope],
      page: { limit: 50, count: 1, hasMore: false, nextCursor: null },
    });

    const paths = fetchMock.mock.calls.map(
      ([request]) => new URL((request as Request).url).pathname,
    );
    expect(paths).toEqual([
      "/api/v1/inventory/overview",
      "/api/v1/inventory/resources",
      "/api/v1/collector/status",
      "/api/v1/collector/runs",
      "/api/v1/collector/scopes",
    ]);
    const queries = fetchMock.mock.calls.map(
      ([request]) => new URL((request as Request).url).searchParams,
    );
    expect(queries[1]?.has("offset")).toBe(false);
    expect(queries[3]?.has("offset")).toBe(false);
    expect(queries[4]?.has("offset")).toBe(false);
  });

  it("sendet opake Cursor für Ressourcen, Runs und Scopes unverändert", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            items: [],
            page: { limit: 1, count: 0, hasMore: false, nextCursor: null },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createInventoryApi("https://hoddmimir.example.test");

    await api.getResources({
      kind: "pbs_snapshot",
      limit: 1,
      cursor: "resource-cursor",
    });
    await api.getCollectorRuns({ limit: 1, cursor: "run-cursor" });
    await api.getCollectorScopes({
      runId: UUID,
      limit: 1,
      cursor: "scope-cursor",
    });

    expect(
      fetchMock.mock.calls.map(([request]) =>
        new URL((request as Request).url).searchParams.get("cursor"),
      ),
    ).toEqual(["resource-cursor", "run-cursor", "scope-cursor"]);
  });

  it.each(["pbs_namespace", "pbs_backup_group", "pbs_snapshot"] as const)(
    "serialisiert den sicheren Ressourcentyp %s",
    async (kind) => {
      const fetchMock = vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            items: [],
            page: { limit: 10, count: 0, hasMore: false, nextCursor: null },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      );
      vi.stubGlobal("fetch", fetchMock);

      await createInventoryApi("https://hoddmimir.example.test").getResources({
        kind,
        limit: 10,
      });

      const url = new URL((fetchMock.mock.calls[0]?.[0] as Request).url);
      expect(url.searchParams.get("kind")).toBe(kind);
      expect(url.href).not.toMatch(
        /owner_auth_id|encryption_fingerprint|verification_upid|files_json|comment/,
      );
    },
  );

  it.each([401, 403] as const)(
    "erhält den HTTP-Status für einen %s-Plain-Object-Fehler",
    async (status) => {
      vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue(
          new Response(
            JSON.stringify({
              error: { code: "forbidden", message: "denied" },
            }),
            { status, headers: { "Content-Type": "application/json" } },
          ),
        ),
      );

      await expect(
        createInventoryApi("https://hoddmimir.example.test").getOverview(),
      ).rejects.toMatchObject({
        httpStatus: status,
        payload: { error: { code: "forbidden", message: "denied" } },
      });
    },
  );
});
