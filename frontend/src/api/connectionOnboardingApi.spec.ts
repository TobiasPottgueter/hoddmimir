import { afterEach, describe, expect, it, vi } from "vitest";
import { createConnectionOnboardingApi } from "./connectionOnboardingApi";

describe("connectionOnboardingApi", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("loads product-bound guidance without credentials", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ product: "pve", commands: [], warnings: [] }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      createConnectionOnboardingApi("https://example.test/root").guidance(
        "pve",
      ),
    ).resolves.toEqual({
      product: "pve",
      commands: [],
      warnings: [],
    });
    const request = fetchMock.mock.calls[0]![0] as Request;
    expect(request.url).toBe(
      "https://example.test/root/api/v1/connections/onboarding/guidance/pve",
    );
    expect(request.credentials).toBe("same-origin");
  });

  it("posts activation with CSRF and idempotency but no invented headers", async () => {
    const result = {
      status: "applied",
      connectionId: "00112233-4455-6677-8899-aabbccddeeff",
      revision: 1,
      onboardingStatus: "first_automatic_scan_pending",
      verification: {
        tls: "tls_verified",
        product: "product_supported",
        scanPermissions: "scan_permissions_verified",
        backupPermissions: null,
        activation: "connection_activated",
        inventory: "first_automatic_scan_pending",
        detectedProduct: "pbs",
        detectedVersion: "4.0",
        warnings: [],
      },
    };
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify(result), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);
    const body = {
      expectedRevision: 0 as const,
      product: "pbs" as const,
      displayName: "PBS",
      endpoint: {
        host: "pbs.example.test",
        port: 8007,
        tlsMode: "system_ca" as const,
        customCaPem: null,
        sha256Fingerprint: null,
      },
      credentials: {
        scan: { tokenId: "hoddmimir@pbs!scan", tokenSecret: "write-only" },
      },
    };

    await expect(
      createConnectionOnboardingApi().activate(body, {
        csrfToken: "csrf",
        idempotencyKey: "operation-1",
      }),
    ).resolves.toEqual(result);
    const request = fetchMock.mock.calls[0]![0] as Request;
    expect(Object.fromEntries(request.headers)).toEqual({
      "content-type": "application/json",
      "idempotency-key": "operation-1",
      "x-csrf-token": "csrf",
    });
    expect(await request.clone().json()).toEqual(body);
  });

  it("posts an endpoint-bound rotation with the same protected headers", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          status: "replayed",
          connectionId: "00112233-4455-6677-8899-aabbccddeeff",
          revision: 3,
          onboardingStatus: "first_automatic_scan_pending",
          verification: {},
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const body = {
      expectedRevision: 2,
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
      product: "pve" as const,
      displayName: "PVE",
      endpoint: {
        host: "pve.example.test",
        port: 8006,
        tlsMode: "system_ca" as const,
        customCaPem: null,
        sha256Fingerprint: null,
      },
      credentials: {
        scan: { tokenId: "hoddmimir@pve!scan", tokenSecret: "scan" },
        backup: { tokenId: "hoddmimir@pve!backup", tokenSecret: "backup" },
      },
    };

    await createConnectionOnboardingApi("https://example.test/root").rotate(
      "00112233-4455-6677-8899-aabbccddeeff",
      body,
      { csrfToken: "csrf", idempotencyKey: "rotate-1" },
    );

    const request = fetchMock.mock.calls[0]![0] as Request;
    expect(request.method).toBe("POST");
    expect(new URL(request.url).pathname).toBe(
      "/root/api/v1/connections/00112233-4455-6677-8899-aabbccddeeff/onboarding/rotate",
    );
    expect(await request.clone().json()).toEqual(body);
  });

  it("uses only the versioned verified endpoint add and update routes", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            status: "applied",
            connectionId: "00112233-4455-6677-8899-aabbccddeeff",
            revision: 3,
            onboardingStatus: "first_automatic_scan_pending",
            verification: {},
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createConnectionOnboardingApi("https://example.test/root");
    const body = {
      expectedRevision: 2,
      product: "pbs" as const,
      displayName: "PBS",
      endpoint: {
        host: "pbs-failover.example.test",
        port: 8007,
        tlsMode: "system_ca" as const,
        customCaPem: null,
        sha256Fingerprint: null,
      },
      credentials: {
        scan: { tokenId: "hoddmimir@pbs!scan", tokenSecret: "scan" },
      },
    };
    const headers = { csrfToken: "csrf", idempotencyKey: "endpoint-1" };
    await api.addEndpoint(
      "00112233-4455-6677-8899-aabbccddeeff",
      body,
      headers,
    );
    await api.updateEndpoint(
      "00112233-4455-6677-8899-aabbccddeeff",
      "11112233-4455-6677-8899-aabbccddeeff",
      body,
      headers,
    );
    expect(
      fetchMock.mock.calls.map((call) => {
        const request = call[0] as Request;
        return [new URL(request.url).pathname, request.method];
      }),
    ).toEqual([
      [
        "/root/api/v1/connections/00112233-4455-6677-8899-aabbccddeeff/onboarding/endpoints",
        "POST",
      ],
      [
        "/root/api/v1/connections/00112233-4455-6677-8899-aabbccddeeff/onboarding/endpoints/11112233-4455-6677-8899-aabbccddeeff",
        "PUT",
      ],
    ]);
  });

  it("returns a typed HTTP failure even for an unreadable error body", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response("not-json", { status: 503 })),
    );

    await expect(
      createConnectionOnboardingApi().guidance("pbs"),
    ).rejects.toEqual({
      httpStatus: 503,
      payload: "not-json",
    });
  });

  it("uses a deterministic absolute origin outside a browser", async () => {
    vi.stubGlobal("window", undefined);
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ product: "pbs", commands: [], warnings: [] }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    await createConnectionOnboardingApi().guidance("pbs");

    expect((fetchMock.mock.calls[0]![0] as Request).url).toBe(
      "http://localhost/api/v1/connections/onboarding/guidance/pbs",
    );
  });
});
