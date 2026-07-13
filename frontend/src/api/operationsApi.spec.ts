import { afterEach, describe, expect, it, vi } from "vitest";
import { createOperationsApi } from "./operationsApi";

describe("backup operations api", () => {
  afterEach(() => vi.unstubAllGlobals());
  it("encodes cursor and sends revisioned command headers", async () => {
    const page = {
      items: [],
      page: { limit: 25, count: 0, hasMore: false, nextCursor: null },
    };
    const fetchMock = vi.fn().mockImplementation((request: Request) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            request.method === "POST"
              ? {
                  status: "applied",
                  requestId: "11111111-1111-1111-1111-111111111111",
                  revision: 1,
                }
              : page,
          ),
          { status: request.method === "POST" ? 201 : 200 },
        ),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createOperationsApi("https://example.test");
    await api.queue(25, "opaque", "pending");
    await api.queue(25);
    await api.dashboard();
    await api.runs(25, "runs", "running");
    await api.run("id/with/slash");
    await api.requestEvents("request", 25, "events");
    await api.runEvents("run", 25);
    await api.logs("run", 25, "logs");
    await api.notifications(25, "notifications", "failure");
    await api.notifications(25);
    await api.notificationHealth();
    await api.manual(
      {
        guestId: "11111111-1111-1111-1111-111111111111",
        policyId: "22222222-2222-2222-2222-222222222222",
        expectedRevision: 3,
      },
      "csrf",
      "manual-1",
    );
    await api.cancel(
      "11111111-1111-1111-1111-111111111111",
      2,
      "csrf",
      "cancel-1",
    );
    const queue = fetchMock.mock.calls[0]?.[0] as Request;
    const command = fetchMock.mock.calls
      .map((call) => call[0] as Request)
      .find((request) => request.url.endsWith("/api/v1/operations/requests"))!;
    expect(new URL(queue.url).searchParams.get("cursor")).toBe("opaque");
    expect(new URL(queue.url).searchParams.get("state")).toBe("pending");
    const notification = fetchMock.mock.calls
      .map((call) => call[0] as Request)
      .find((request) =>
        request.url.includes("/api/v1/operations/notifications?"),
      )!;
    expect(new URL(notification.url).searchParams.get("kind")).toBe("failure");
    expect(command.headers.get("Idempotency-Key")).toBe("manual-1");
    expect(command.headers.get("X-CSRF-Token")).toBe("csrf");
  });
});
