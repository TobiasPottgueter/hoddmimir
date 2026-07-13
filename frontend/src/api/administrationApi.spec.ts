import { afterEach, describe, expect, it, vi } from "vitest";

import { UUID } from "@/test/fixtures";
import {
  administrationFailure,
  createAdministrationApi,
} from "./administrationApi";

const page = {
  items: [],
  page: { limit: 50, count: 0, hasMore: false, nextCursor: null },
};

describe("Administration API", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("verwendet versionierte GET-Pfade und vollständige Filter", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(page), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createAdministrationApi("https://hoddmimir.example.test");

    await api.health();
    await api.users({
      limit: 50,
      cursor: "next",
      search: "ada",
      enabled: true,
    });
    await api.roles(25, "roles-next");
    await api.audit({
      limit: 10,
      cursor: "audit-next",
      actorUserId: UUID,
      eventType: "user_created",
      outcome: "succeeded",
    });
    await api.auditEvent(UUID);

    const requests = fetchMock.mock.calls.map((call) => call[0] as Request);
    expect(requests.map((request) => new URL(request.url).pathname)).toEqual([
      "/api/v1/admin/health",
      "/api/v1/admin/users",
      "/api/v1/admin/roles",
      "/api/v1/admin/audit-events",
      `/api/v1/admin/audit-events/${UUID}`,
    ]);
    expect(Object.fromEntries(new URL(requests[1]!.url).searchParams)).toEqual({
      limit: "50",
      cursor: "next",
      search: "ada",
      enabled: "true",
    });
  });

  it("sendet alle Commands mit CSRF, Idempotenz und write-only Passwort", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ status: "applied", revision: 2 }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createAdministrationApi("https://hoddmimir.example.test");
    const headers = { csrfToken: "csrf", idempotencyKey: "request-1" };

    await api.createUser(
      {
        expectedRevision: 0,
        username: "ada",
        displayName: "Ada",
        password: "correct horse battery staple",
        roles: ["viewer"],
      },
      headers,
    );
    await api.updateUser(
      UUID,
      {
        expectedRevision: 1,
        displayName: "Ada Lovelace",
        password: "new password value",
      },
      headers,
    );
    await api.disableUser(UUID, { expectedRevision: 2 }, headers);
    await api.replaceRoles(
      UUID,
      { expectedRevision: 2, roles: ["admin"] },
      headers,
    );

    const requests = fetchMock.mock.calls.map((call) => call[0] as Request);
    expect(
      requests.map((request) => [
        request.method,
        new URL(request.url).pathname,
      ]),
    ).toEqual([
      ["POST", "/api/v1/admin/users"],
      ["PUT", `/api/v1/admin/users/${UUID}`],
      ["POST", `/api/v1/admin/users/${UUID}/disable`],
      ["PUT", `/api/v1/admin/users/${UUID}/roles`],
    ]);
    for (const request of requests) {
      expect(request.headers.get("Idempotency-Key")).toBe("request-1");
      expect(request.headers.get("X-CSRF-Token")).toBe("csrf");
    }
    expect(await requests[0]!.clone().json()).toMatchObject({
      password: "correct horse battery staple",
    });
  });

  it("bewahrt den HTTP-Status eines Serverfehlers für die sichere Projektion", async () => {
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
      createAdministrationApi("https://hoddmimir.example.test").users({
        limit: 50,
      }),
    ).rejects.toMatchObject({ httpStatus: 503 });
  });

  it.each([
    [{ httpStatus: 400 }, { kind: "invalid" }],
    [{ httpStatus: 403 }, { kind: "permission" }],
    [
      { httpStatus: 409, payload: { error: { currentRevision: 7 } } },
      { kind: "conflict", currentRevision: 7 },
    ],
    [
      { httpStatus: 422, payload: { error: { blockers: ["self_lockout"] } } },
      { kind: "blocked", blockers: ["self_lockout"] },
    ],
    [{ httpStatus: 503 }, { kind: "unavailable" }],
    [{ httpStatus: 409, payload: { error: {} } }, { kind: "unknown" }],
    [
      { httpStatus: 422, payload: { error: { blockers: [1] } } },
      { kind: "unknown" },
    ],
    [null, { kind: "unknown" }],
  ])("projiziert Fehler fail-closed", (error, expected) => {
    expect(administrationFailure(error)).toEqual(expected);
  });
});
