import { afterEach, describe, expect, it, vi } from "vitest";

import { UUID } from "@/test/fixtures";
import { createPolicyApi } from "./policyApi";

const emptyPage = {
  items: [],
  page: { limit: 20, count: 0, hasMore: false, nextCursor: null },
};

describe("generierter Policy-API-Client", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("liest Policies und Auswahl ausschließlich über GET", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(emptyPage), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createPolicyApi("https://hoddmimir.example.test");

    await api.getPolicies({
      limit: 20,
      cursor: "policy-cursor",
      search: "Nacht",
      status: "enabled",
    });
    await api.getSelection(UUID, { limit: 20, cursor: "selection-cursor" });

    const policies = new URL((fetchMock.mock.calls[0]?.[0] as Request).url);
    const selectionRequest = fetchMock.mock.calls[1]?.[0] as Request;
    const selection = new URL(selectionRequest.url);
    expect((fetchMock.mock.calls[0]?.[0] as Request).method).toBe("GET");
    expect(policies.pathname).toBe("/api/v1/policies");
    expect(Object.fromEntries(policies.searchParams)).toEqual({
      limit: "20",
      cursor: "policy-cursor",
      search: "Nacht",
      status: "enabled",
    });
    expect(selectionRequest.method).toBe("GET");
    expect(selection.pathname).toBe(`/api/v1/policies/${UUID}/selection`);
    expect(Object.fromEntries(selection.searchParams)).toEqual({
      limit: "20",
      cursor: "selection-cursor",
    });
  });

  it("bewahrt HTTP-Fehler für fail-closed Stores", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          new Response(
            JSON.stringify({ error: { code: "read_model_unavailable" } }),
            { status: 503, headers: { "Content-Type": "application/json" } },
          ),
        ),
    );
    await expect(
      createPolicyApi("https://example.test").getPolicies({ limit: 20 }),
    ).rejects.toMatchObject({ httpStatus: 503 });
  });
});
