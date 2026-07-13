import { afterEach, describe, expect, it, vi } from "vitest";
import { createAuthApi } from "./authApi";

describe("auth api", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("sendet Login ohne Tokenpersistenz und Logout mit Memory-CSRF", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            user: { id: "id", username: "u", permissions: [] },
            csrfToken: "csrf",
            idleExpiresAt: "i",
            absoluteExpiresAt: "a",
          }),
          { status: 200 },
        ),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);
    const api = createAuthApi("https://example.test");
    await api.login("user", "secret");
    await api.logout("csrf");
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({
      method: "POST",
      credentials: "same-origin",
    });
    expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({
      headers: { "X-CSRF-Token": "csrf" },
    });
  });

  it("liefert geschlossene HTTP-Fehler", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response("not-json", { status: 401 })),
    );
    await expect(createAuthApi().session()).rejects.toMatchObject({
      httpStatus: 401,
    });
  });

  it("liest eine Session und verwirft nicht-JSON Logout-Fehler sicher", async () => {
    const session = {
      user: { id: "id", username: "u", permissions: [] },
      csrfToken: "csrf",
      idleExpiresAt: "i",
      absoluteExpiresAt: "a",
    };
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValueOnce(
          new Response(JSON.stringify(session), { status: 200 }),
        )
        .mockResolvedValueOnce(new Response("not-json", { status: 500 })),
    );
    const api = createAuthApi("https://example.test");
    await expect(api.session()).resolves.toEqual(session);
    await expect(api.logout("csrf")).rejects.toMatchObject({
      httpStatus: 500,
      payload: null,
    });
  });
});
