import { afterEach, describe, expect, it, vi } from "vitest";
import { UUID, OTHER_UUID } from "@/test/fixtures";
import { createConnectionApi } from "./connectionApi";

const headers = { csrfToken: "csrf", idempotencyKey: "request-1" };
describe("Connection API", () => {
  afterEach(() => vi.unstubAllGlobals());
  it("exportiert nur Reads und sichere administrative Commands", async () => {
    const fetchMock = vi.fn().mockImplementation((request: Request) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            new URL(request.url).pathname.endsWith(`/connections/${UUID}`)
              ? {
                  id: UUID,
                  displayName: "PVE",
                  product: "pve",
                  enabled: false,
                  revision: 1,
                  createdAt: "2026-07-13T00:00:00.000000Z",
                  updatedAt: "2026-07-13T00:00:00.000000Z",
                  endpoints: [],
                  credentials: [],
                }
              : { items: [], status: "applied", revision: 2 },
          ),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createConnectionApi("https://example.test");
    await api.list();
    await api.detail(UUID);
    await api.update(
      UUID,
      { expectedRevision: 1, displayName: "PVE 2" },
      headers,
    );
    await api.disable(UUID, { expectedRevision: 2 }, headers);
    await api.disableEndpoint(
      UUID,
      OTHER_UUID,
      { expectedRevision: 2 },
      headers,
    );
    const requests = fetchMock.mock.calls.map((call) => call[0] as Request);
    expect(requests.map((r) => [r.method, new URL(r.url).pathname])).toEqual([
      ["GET", "/api/v1/connections"],
      ["GET", `/api/v1/connections/${UUID}`],
      ["PUT", `/api/v1/connections/${UUID}`],
      ["POST", `/api/v1/connections/${UUID}/disable`],
      ["POST", `/api/v1/connections/${UUID}/endpoints/${OTHER_UUID}/disable`],
    ]);
    for (const request of requests.slice(2)) {
      expect(request.headers.get("Idempotency-Key")).toBe("request-1");
      expect(request.headers.get("X-CSRF-Token")).toBe("csrf");
    }
  });
  it("bewahrt Fehlerstatus für die fail-closed Projektion", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ error: { code: "unavailable" } }), {
          status: 503,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    await expect(
      createConnectionApi("https://example.test").list(),
    ).rejects.toMatchObject({
      httpStatus: 503,
    });
  });
});
