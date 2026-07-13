import { afterEach, describe, expect, it, vi } from "vitest";

import { UUID } from "@/test/fixtures";
import type { PolicyCommandRequest } from "@/api/generated/types.gen";
import {
  configurationCommandFailure,
  createConfigurationApi,
} from "./configurationApi";

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

  it("lädt konfigurierte Ziele ausschließlich per GET mit Such- und Statusfilter", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify(emptyPage), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      createConfigurationApi("https://hoddmimir.example.test").getBackupTargets(
        {
          limit: 20,
          cursor: "configured-cursor",
          search: "Primär",
          enabled: false,
        },
      ),
    ).resolves.toEqual(emptyPage);

    const request = fetchMock.mock.calls[0]?.[0] as Request;
    const url = new URL(request.url);
    expect(request.method).toBe("GET");
    expect(url.pathname).toBe("/api/v1/backup-targets");
    expect(Object.fromEntries(url.searchParams)).toEqual({
      limit: "20",
      cursor: "configured-cursor",
      search: "Primär",
      enabled: "false",
    });
  });

  it("sendet alle Konfigurationscommands über den generierten Client mit CSRF und Idempotenz", async () => {
    const fetchMock = vi.fn().mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ status: "applied", revision: 2 }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = createConfigurationApi("https://hoddmimir.example.test");
    const headers = { idempotencyKey: "request-1", csrfToken: "csrf-token" };
    const target = {
      expectedRevision: 1,
      displayName: "Target",
      connectionId: UUID,
      clusterId: UUID,
      storageId: UUID,
      minimumFreeBytes: "1024",
      fixedParallelLimit: 2,
      pbsConnectionId: null,
      pbsDatastoreId: null,
      pbsNamespaceId: null,
      allowedNodeIds: [UUID] as string[],
    } as const;
    const policy = {
      expectedRevision: 1,
      displayName: "Policy",
      connectionId: UUID,
      clusterId: UUID,
      targetId: UUID,
      priority: 1,
      backupMode: "snapshot",
      compression: "zstd",
      maximumAgeSeconds: "60",
      bytesWrittenThreshold: null,
      cooldownSeconds: null,
      schedule: "collector_cycle",
      legacyMaxfiles: null,
      keepAll: null,
      keepLast: 1,
      keepHourly: null,
      keepDaily: null,
      keepWeekly: null,
      keepMonthly: null,
      keepYearly: null,
      retentionExecutionEnabled: false,
      failureNotificationRecipients: [],
    } satisfies PolicyCommandRequest;
    const revision = { expectedRevision: 1 };
    const bulk = { expectedRevision: 1, entries: [{ id: UUID }] };
    await api.createBackupTarget(target, headers);
    await api.updateBackupTarget(UUID, target, headers);
    await api.enableBackupTarget(UUID, revision, headers);
    await api.disableBackupTarget(UUID, revision, headers);
    await api.createPolicy(policy, headers);
    await api.updatePolicy(UUID, policy, headers);
    await api.enablePolicy(UUID, revision, headers);
    await api.disablePolicy(UUID, revision, headers);
    await api.upsertPolicySelection(UUID, bulk, headers);
    await api.disablePolicySelection(UUID, bulk, headers);
    await api.upsertPolicyGuestOverrides(UUID, bulk, headers);
    await api.disablePolicyGuestOverrides(UUID, bulk, headers);

    expect(fetchMock).toHaveBeenCalledTimes(12);
    const requests = fetchMock.mock.calls.map((call) => call[0] as Request);
    expect(
      requests.map((request) => [
        request.method,
        new URL(request.url).pathname,
      ]),
    ).toEqual([
      ["POST", "/api/v1/backup-targets"],
      ["PUT", `/api/v1/backup-targets/${UUID}`],
      ["POST", `/api/v1/backup-targets/${UUID}/enable`],
      ["POST", `/api/v1/backup-targets/${UUID}/disable`],
      ["POST", "/api/v1/policies"],
      ["PUT", `/api/v1/policies/${UUID}`],
      ["POST", `/api/v1/policies/${UUID}/enable`],
      ["POST", `/api/v1/policies/${UUID}/disable`],
      ["PUT", `/api/v1/policies/${UUID}/selection`],
      ["POST", `/api/v1/policies/${UUID}/selection/disable`],
      ["PUT", `/api/v1/policies/${UUID}/guest-overrides`],
      ["POST", `/api/v1/policies/${UUID}/guest-overrides/disable`],
    ]);
    for (const request of requests) {
      expect(request.headers.get("Idempotency-Key")).toBe("request-1");
      expect(request.headers.get("X-CSRF-Token")).toBe("csrf-token");
    }
  });

  it.each([
    [400, { error: { code: "invalid_request" } }, { kind: "invalid" }],
    [403, { error: { code: "permission_denied" } }, { kind: "permission" }],
    [
      409,
      { error: { code: "revision_conflict", currentRevision: 7 } },
      { kind: "conflict", currentRevision: 7 },
    ],
    [
      422,
      {
        error: {
          code: "activation_blocked",
          blockers: ["executor_evidence_missing"],
        },
      },
      { kind: "blocked", blockers: ["executor_evidence_missing"] },
    ],
    [
      503,
      { error: { code: "configuration_unavailable" } },
      { kind: "unavailable" },
    ],
  ] as const)(
    "projiziert Command-HTTP-%s typisiert",
    (status, payload, expected) => {
      expect(
        configurationCommandFailure({ httpStatus: status, payload }),
      ).toEqual(expected);
    },
  );

  it("behandelt unvollständige Fehlerpayloads geschlossen", () => {
    expect(
      configurationCommandFailure({ httpStatus: 409, payload: {} }),
    ).toEqual({ kind: "unknown" });
    expect(
      configurationCommandFailure({
        httpStatus: 422,
        payload: { error: { blockers: [1] } },
      }),
    ).toEqual({ kind: "unknown" });
    expect(configurationCommandFailure(new Error("network"))).toEqual({
      kind: "unknown",
    });
  });
});
