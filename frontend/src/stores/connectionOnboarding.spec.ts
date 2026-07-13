import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ConnectionOnboardingApi } from "@/api/connectionOnboardingApi";
import type {
  OnboardingActivationRequestWritable as OnboardingActivationRequest,
  OnboardingMutationResult as OnboardingActivationResult,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";
import {
  onboardingFailure,
  useConnectionOnboardingStore,
} from "./connectionOnboarding";

const result: OnboardingActivationResult = {
  status: "applied",
  connectionId: "00112233-4455-6677-8899-aabbccddeeff",
  revision: 1,
  onboardingStatus: "first_automatic_scan_pending",
  verification: {
    tls: "tls_verified",
    product: "product_supported",
    scanPermissions: "scan_permissions_verified",
    backupPermissions: "backup_permissions_verified",
    activation: "connection_activated",
    inventory: "first_automatic_scan_pending",
    detectedProduct: "pve",
    detectedVersion: "9.0",
    warnings: [],
  },
};
const request: OnboardingActivationRequest = {
  expectedRevision: 0,
  product: "pve",
  displayName: "PVE",
  endpoint: {
    host: "pve.example.test",
    port: 8006,
    tlsMode: "system_ca",
    customCaPem: null,
    sha256Fingerprint: null,
  },
  credentials: {
    scan: { tokenId: "hoddmimir@pve!scan", tokenSecret: "scan" },
    backup: { tokenId: "hoddmimir@pve!backup", tokenSecret: "backup" },
  },
};
const api = (): ConnectionOnboardingApi => ({
  guidance: vi.fn().mockResolvedValue({
    product: "pve",
    commands: [],
    warnings: [],
  }),
  activate: vi.fn().mockResolvedValue(result),
  rotate: vi.fn().mockResolvedValue(result),
  addEndpoint: vi.fn().mockResolvedValue({
    ...result,
    onboardingStatus: "endpoint_verified",
    verification: {
      ...result.verification,
      inventory: "first_automatic_scan_pending",
    },
  }),
  updateEndpoint: vi.fn().mockResolvedValue({
    ...result,
    onboardingStatus: "endpoint_verified",
    verification: {
      ...result.verification,
      inventory: "first_automatic_scan_pending",
    },
  }),
});

describe("connectionOnboarding store", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    useAuthStore().$patch({
      initialized: true,
      csrfToken: "csrf",
      principal: {
        id: result.connectionId,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
    });
  });

  it("loads matching guidance and rejects mismatched or unavailable guidance", async () => {
    const store = useConnectionOnboardingStore();
    const service = api();
    expect(await store.loadGuidance("pve", service)).toBe(true);
    expect(store.guidance?.product).toBe("pve");
    expect(store.guidanceLoading).toBe(false);
    vi.mocked(service.guidance).mockResolvedValueOnce({
      product: "pbs",
      commands: [],
      warnings: [],
    });
    expect(await store.loadGuidance("pve", service)).toBe(false);
    vi.mocked(service.guidance).mockRejectedValueOnce(new Error("offline"));
    expect(await store.loadGuidance("pbs", service)).toBe(false);
    expect(store.error).toContain("Einzelbefehle");
    vi.mocked(service.guidance).mockResolvedValueOnce({
      product: "pve",
      commands: [
        {
          id: "unsafe",
          command: "hidden",
          purpose: "unsafe",
          mutatesRemote: false,
          containsSecret: true as false,
        },
      ],
      warnings: [],
    });
    expect(await store.loadGuidance("pve", service)).toBe(false);
  });

  it("rotates one selected endpoint through the verified contract", async () => {
    const store = useConnectionOnboardingStore();
    const service = api();
    const body = {
      ...request,
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
    };
    expect(
      await store.rotate(result.connectionId, body, service, () => "rotate-1"),
    ).toBe(true);
    expect(service.rotate).toHaveBeenCalledWith(result.connectionId, body, {
      csrfToken: "csrf",
      idempotencyKey: "rotate-1",
    });
  });

  it("adds and updates failover endpoints only through verification", async () => {
    const store = useConnectionOnboardingStore();
    const service = api();
    expect(
      await store.addEndpoint(
        result.connectionId,
        request,
        service,
        () => "endpoint-add",
      ),
    ).toBe(true);
    expect(service.addEndpoint).toHaveBeenCalledWith(
      result.connectionId,
      request,
      { csrfToken: "csrf", idempotencyKey: "endpoint-add" },
    );
    expect(
      await store.updateEndpoint(
        result.connectionId,
        "11112233-4455-6677-8899-aabbccddeeff",
        request,
        service,
        () => "endpoint-update",
      ),
    ).toBe(true);
    expect(service.updateEndpoint).toHaveBeenCalledWith(
      result.connectionId,
      "11112233-4455-6677-8899-aabbccddeeff",
      request,
      { csrfToken: "csrf", idempotencyKey: "endpoint-update" },
    );
  });

  it("activates with the authenticated CSRF and stable idempotency key", async () => {
    const store = useConnectionOnboardingStore();
    const service = api();
    expect(await store.activate(request, service, () => "operation-1")).toBe(
      true,
    );
    expect(service.activate).toHaveBeenCalledWith(request, {
      csrfToken: "csrf",
      idempotencyKey: "operation-1",
    });
    expect(store.result).toEqual(result);
    expect(store.verification).toEqual(result.verification);
    expect(store.submitting).toBe(false);
  });

  it.each([
    [400, "invalid_request", "ungültig"],
    [403, "permission_denied", "Berechtigung"],
    [409, "revision_conflict", "zwischenzeitlich"],
    [422, "onboarding_verification_failed", "Remote-Prüfung"],
    [503, "onboarding_unavailable", "nicht verfügbar"],
    [500, "unexpected", "nicht sicher gespeichert"],
  ])(
    "maps HTTP %i failures without losing safe evidence",
    async (status, code, message) => {
      const store = useConnectionOnboardingStore();
      const service = api();
      vi.mocked(service.activate).mockRejectedValueOnce({
        httpStatus: status,
        payload: {
          error: {
            code,
            currentRevision: 7,
            issues: [
              {
                code: "required_permission_missing",
                credential: "scan",
                path: "/storage",
                privilege: "Datastore.Audit",
              },
            ],
            verification: result.verification,
          },
        },
      });
      expect(await store.activate(request, service)).toBe(false);
      expect(store.error).toContain(message);
      expect(store.conflictRevision).toBe(7);
      expect(store.issues).toHaveLength(1);
      expect(store.verification).toEqual(result.verification);
    },
  );

  it("fails closed without permission and resets all transient state", async () => {
    const store = useConnectionOnboardingStore();
    useAuthStore().$patch({ principal: null, csrfToken: null });
    expect(store.canManage).toBe(false);
    expect(await store.loadGuidance("pve", api())).toBe(false);
    expect(await store.activate(request, api())).toBe(false);
    store.reset();
    expect(store.error).toBeNull();
    expect(store.guidance).toBeNull();
    expect(store.result).toBeNull();
  });

  it("parses malformed payloads as an unknown failure", () => {
    expect(onboardingFailure(new Error("closed"))).toEqual({
      kind: "unknown",
      revision: null,
      issues: [],
      verification: null,
    });
    expect(
      onboardingFailure({
        httpStatus: 422,
        payload: {
          error: { code: "onboarding_verification_failed", issues: "bad" },
        },
      }),
    ).toMatchObject({ kind: "verification", issues: [] });
  });
});
