import { describe, expect, it } from "vitest";
import {
  canonicalTokenIds,
  inventoryStatusDescription,
  issueMessage,
  useConnectionOnboarding,
  verificationLabel,
  verificationSeverity,
  warningMessage,
} from "./useConnectionOnboarding";

const fingerprint = Array.from({ length: 32 }, () => "AA").join(":");
const normalizedFingerprint = "aa".repeat(32);
const certificate =
  "-----BEGIN CERTIFICATE-----\nQA\n-----END CERTIFICATE-----";

describe("useConnectionOnboarding", () => {
  it("switches PVE/PBS defaults and clears write-only secrets", () => {
    const wizard = useConnectionOnboarding();
    wizard.form.scanTokenSecret = "scan";
    wizard.form.backupTokenSecret = "backup";
    wizard.step.value = 3;

    wizard.selectProduct("pbs");

    expect(wizard.mode.value).toBe("activate");
    expect(wizard.connectionId.value).toBeNull();
    expect(wizard.endpointId.value).toBeNull();
    expect(wizard.expectedRevision.value).toBe(0);
    expect(wizard.product.value).toBe("pbs");
    expect(wizard.form.port).toBe(8007);
    expect(wizard.form.scanTokenId).toBe(canonicalTokenIds.pbs.scan);
    expect(wizard.form.scanTokenSecret).toBe("");
    expect(wizard.form.backupTokenSecret).toBe("");
    expect(wizard.step.value).toBe(1);
    wizard.selectProduct("pve");
    expect(wizard.form.port).toBe(8006);
    expect(wizard.form.backupTokenId).toBe(canonicalTokenIds.pve.backup);
  });

  it("keeps exactly one valid TLS trust material", () => {
    const wizard = useConnectionOnboarding();
    expect(wizard.trustValid.value).toBe(true);
    wizard.form.customCaPem = "unexpected";
    expect(wizard.trustValid.value).toBe(false);
    wizard.selectTlsMode("custom_ca");
    expect(wizard.form.sha256Fingerprint).toBe("");
    expect(wizard.trustValid.value).toBe(false);
    wizard.form.customCaPem = certificate;
    expect(wizard.trustValid.value).toBe(true);
    expect(wizard.activationRequest().endpoint.customCaPem).toBe(certificate);
    wizard.selectTlsMode("sha256_fingerprint");
    expect(wizard.form.customCaPem).toBe("");
    expect(wizard.trustValid.value).toBe(false);
    wizard.form.sha256Fingerprint = "not-a-sha256-pin";
    expect(wizard.trustValid.value).toBe(false);
    wizard.form.sha256Fingerprint = fingerprint;
    expect(wizard.trustValid.value).toBe(true);
    expect(wizard.activationRequest().endpoint.sha256Fingerprint).toBe(
      normalizedFingerprint,
    );
    wizard.selectTlsMode("system_ca");
    expect(wizard.form.sha256Fingerprint).toBe("");
  });

  it("builds trimmed exclusive PVE and PBS activation requests", () => {
    const wizard = useConnectionOnboarding();
    Object.assign(wizard.form, {
      displayName: " PVE ",
      host: " pve.example.test ",
      scanTokenSecret: "scan-secret",
      backupTokenSecret: "backup-secret",
    });
    expect(wizard.endpointValid.value).toBe(true);
    expect(wizard.credentialsValid.value).toBe(true);
    expect(wizard.activationRequest()).toMatchObject({
      expectedRevision: 0,
      displayName: "PVE",
      endpoint: { host: "pve.example.test" },
      credentials: {
        backup: {
          tokenId: canonicalTokenIds.pve.backup,
          tokenSecret: "backup-secret",
        },
      },
    });
    wizard.form.port = 0;
    expect(wizard.endpointValid.value).toBe(false);
    wizard.form.port = 65536;
    expect(wizard.endpointValid.value).toBe(false);
    wizard.form.port = 8006;
    wizard.form.host = " ";
    expect(wizard.endpointValid.value).toBe(false);
    wizard.form.host = "pve.example.test";
    wizard.form.displayName = " ";
    expect(wizard.endpointValid.value).toBe(false);
    wizard.form.displayName = "PVE";
    wizard.form.scanTokenId = "wrong";
    expect(wizard.tokenIdsValid.value).toBe(false);
    expect(wizard.credentialsValid.value).toBe(false);
    wizard.selectProduct("pbs");
    Object.assign(wizard.form, {
      displayName: "PBS",
      host: "pbs.example.test",
      scanTokenSecret: "pbs-secret",
    });
    const request = wizard.activationRequest();
    expect(request.product).toBe("pbs");
    if (request.product !== "pbs") throw new Error("Expected PBS request.");
    expect("backup" in request.credentials).toBe(false);
    expect(request.endpoint.port).toBe(8007);
  });

  it("prepares endpoint-bound rotation and never reconstructs missing CA trust", () => {
    const wizard = useConnectionOnboarding();
    expect(() => wizard.rotationRequest()).toThrow("selected endpoint");
    wizard.prepareConnectionOperation("rotate", {
      connectionId: "00112233-4455-6677-8899-aabbccddeeff",
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
      revision: 7,
      product: "pve",
      displayName: "PVE Rotation",
      endpoint: {
        host: "pve.example.test",
        port: 8006,
        tlsMode: "custom_ca",
        customCaPem: null,
        sha256Fingerprint: null,
      },
    });
    expect(wizard.mode.value).toBe("rotate");
    expect(wizard.expectedRevision.value).toBe(7);
    expect(wizard.trustValid.value).toBe(false);
    expect(wizard.form.customCaPem).toBe("");
    wizard.form.customCaPem = certificate;
    wizard.form.scanTokenSecret = "scan";
    wizard.form.backupTokenSecret = "backup";
    expect(wizard.rotationRequest()).toMatchObject({
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
      expectedRevision: 7,
      product: "pve",
      endpoint: { customCaPem: certificate },
    });
  });

  it("prepares verified endpoint add and update operations", () => {
    const wizard = useConnectionOnboarding();
    const base = {
      connectionId: "00112233-4455-6677-8899-aabbccddeeff",
      revision: 4,
      product: "pbs" as const,
      displayName: "PBS",
    };
    wizard.prepareConnectionOperation("endpoint_add", {
      ...base,
      endpointId: null,
      endpoint: null,
    });
    expect(wizard.mode.value).toBe("endpoint_add");
    expect(wizard.form).toMatchObject({
      host: "",
      port: 8007,
      tlsMode: "system_ca",
      scanTokenId: canonicalTokenIds.pbs.scan,
    });
    const pbsEndpointRequest = wizard.endpointMutationRequest();
    expect(pbsEndpointRequest.product).toBe("pbs");
    expect("backup" in pbsEndpointRequest.credentials).toBe(false);
    wizard.prepareConnectionOperation("endpoint_update", {
      ...base,
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
      endpoint: {
        host: "pbs-failover.example.test",
        port: 8007,
        tlsMode: "sha256_fingerprint",
        customCaPem: null,
        sha256Fingerprint: normalizedFingerprint,
      },
    });
    expect(wizard.mode.value).toBe("endpoint_update");
    expect(wizard.endpointId.value).toContain("11112233");
    expect(wizard.form.sha256Fingerprint).toBe(normalizedFingerprint);
    expect(wizard.rotationRequest()).toMatchObject({
      product: "pbs",
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
    });
  });

  it("explains every closed issue and warning with path evidence", () => {
    for (const code of [
      "authentication_failed",
      "tls_verification_failed",
      "tls_fingerprint_mismatch",
      "remote_unavailable",
      "invalid_remote_response",
      "product_mismatch",
      "version_evidence_mismatch",
      "unsupported_version",
      "role_missing",
      "role_definition_mismatch",
      "credential_missing",
      "token_identity_invalid",
      "token_identities_not_separated",
      "required_permission_missing",
      "permission_not_propagated",
      "no_access_override",
      "forbidden_permission_present",
      "additional_read_only_permission",
      "application_permission_denied",
      "future_closed_code",
    ]) {
      expect(
        issueMessage({
          code: code as never,
          severity: "error",
          credential: "scan",
          path: "/storage",
          privilege: "Datastore.Audit",
        }),
      ).toContain("/storage");
    }
    expect(
      issueMessage({
        code: "authentication_failed",
        severity: "error",
        credential: null,
        path: null,
        privilege: null,
      }),
    ).toBe("Das Token-Secret wurde abgewiesen.");
    expect(
      warningMessage({
        code: "additional_read_only_permission",
        severity: "warning",
        credential: null,
        path: "/",
        privilege: "Sys.Audit",
      }),
    ).toContain("Sys.Audit");
    expect(
      warningMessage({
        code: "future_warning" as never,
        severity: "warning",
        credential: null,
        path: null,
        privilege: null,
      }),
    ).toContain("Read-only-Abweichung");
  });

  it("maps every verification state to a closed label and severity", () => {
    for (const value of [
      "tls_verified",
      "product_supported",
      "scan_permissions_verified",
      "backup_permissions_verified",
      "connection_activated",
      "first_automatic_scan_pending",
      "inventory_verified",
      "inventory_partial",
      "inventory_failed",
      "first_automatic_scan_pending",
      "failed",
      "not_activated",
      "not_started",
    ]) {
      expect(verificationLabel(value)).not.toContain("Unbekannter Zustand");
    }
    expect(verificationLabel("future_state")).toContain("Unbekannter Zustand");
    expect(verificationSeverity("tls_verified")).toBe("success");
    expect(verificationSeverity("first_automatic_scan_pending")).toBe("warn");
    expect(verificationSeverity("failed")).toBe("danger");
    expect(verificationSeverity("not_started")).toBe("secondary");
  });

  it("explains every persisted inventory state and fails closed", () => {
    expect(
      inventoryStatusDescription("first_automatic_scan_pending"),
    ).toContain("Collector-Rasterpunkt");
    expect(inventoryStatusDescription("inventory_verified")).toContain(
      "vollständig",
    );
    expect(inventoryStatusDescription("inventory_partial")).toContain(
      "teilweise",
    );
    expect(inventoryStatusDescription("inventory_failed")).toContain(
      "nicht bestätigen",
    );
    expect(inventoryStatusDescription(null)).toContain("kein atomar");
    expect(inventoryStatusDescription("future_state")).toContain("kein atomar");
  });
});
