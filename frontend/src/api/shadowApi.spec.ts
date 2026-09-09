import { afterEach, describe, expect, it, vi } from "vitest";
import { createShadowApi } from "./shadowApi";

describe("shadow api", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("kodiert Cursor und Detail-ID und sendet Same-Origin-Cookies", async () => {
    const response = {
      items: [],
      page: { limit: 20, count: 0, hasMore: false, nextCursor: null },
    };
    const fetchMock = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(
          new Response(JSON.stringify(response), { status: 200 }),
        ),
      );
    vi.stubGlobal("fetch", fetchMock);
    const api = createShadowApi("https://example.test");
    await api.evaluations(20, "opaque cursor");
    await api.decisions({ limit: 20 });
    await api.decision("id/with/slash");
    const evaluations = fetchMock.mock.calls[0]?.[0] as Request;
    const decisions = fetchMock.mock.calls[1]?.[0] as Request;
    const detail = fetchMock.mock.calls[2]?.[0] as Request;
    expect(new URL(evaluations.url).searchParams.get("cursor")).toBe(
      "opaque cursor",
    );
    expect(decisions.url).not.toContain("cursor=");
    expect(detail.url).toContain("id%2Fwith%2Fslash");
    expect(evaluations.credentials).toBe("same-origin");
  });

  it("liefert geschlossene HTTP-Fehler", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          new Response(
            JSON.stringify({ error: { code: "read_model_unavailable" } }),
            { status: 503 },
          ),
        ),
    );
    await expect(
      createShadowApi("https://example.test").decisions({ limit: 20 }),
    ).rejects.toMatchObject({ httpStatus: 503 });
  });
});
