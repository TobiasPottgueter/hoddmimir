import { computed, reactive, ref } from "vue";
import type {
  OnboardingActivationRequestWritable as OnboardingActivationRequest,
  OnboardingEndpointMutationRequestWritable as OnboardingEndpointMutationRequest,
  OnboardingIssue,
  OnboardingProduct,
  OnboardingRotationRequestWritable as OnboardingRotationRequest,
  TlsMode as OnboardingTlsMode,
} from "@/api/generated/types.gen";

type OnboardingWarning = OnboardingIssue;

export type OnboardingMode =
  "activate" | "rotate" | "endpoint_add" | "endpoint_update";

export interface OnboardingConnectionSeed {
  connectionId: string;
  endpointId: string | null;
  revision: number;
  product: OnboardingProduct;
  displayName: string;
  endpoint: {
    host: string;
    port: number;
    tlsMode: OnboardingTlsMode;
    customCaPem: string | null;
    sha256Fingerprint: string | null;
  } | null;
}

export const canonicalTokenIds = {
  pve: {
    scan: "hoddmimir@pve!scan",
    backup: "hoddmimir@pve!backup",
  },
  pbs: { scan: "hoddmimir@pbs!scan" },
} as const;

const issueLabels: Readonly<Record<string, string>> = {
  authentication_failed: "Das Token-Secret wurde abgewiesen.",
  tls_verification_failed: "Das TLS-Vertrauen konnte nicht bestätigt werden.",
  tls_fingerprint_mismatch: "Der SHA-256-Fingerprint stimmt nicht überein.",
  remote_unavailable: "Das Proxmox-System ist derzeit nicht erreichbar.",
  invalid_remote_response:
    "Das Proxmox-System hat keine sicher auswertbare Antwort geliefert.",
  product_mismatch: "Am Endpoint wurde das falsche Proxmox-Produkt erkannt.",
  version_evidence_mismatch:
    "Die beiden Laufzeit-Identitäten melden widersprüchliche Versionen.",
  unsupported_version: "Die erkannte Proxmox-Version wird nicht unterstützt.",
  role_missing: "Eine erforderliche Rolle fehlt.",
  role_definition_mismatch:
    "Eine vorhandene Rolle entspricht nicht dem sicheren Zielzustand.",
  credential_missing: "Eine erforderliche Laufzeit-Identität fehlt.",
  token_identity_invalid: "Die Token-Identität ist nicht kanonisch.",
  token_identities_not_separated:
    "Scanner- und Backup-Token sind nicht wirksam getrennt.",
  required_permission_missing: "Ein erforderliches Recht fehlt.",
  permission_not_propagated: "Ein erforderliches Recht ist nicht propagiert.",
  no_access_override: "Ein NoAccess-Eintrag blockiert das erforderliche Recht.",
  forbidden_permission_present:
    "Ein schreibendes oder administratives Zusatzrecht ist wirksam.",
  additional_read_only_permission:
    "Ein zusätzliches Read-only-Recht ist wirksam.",
  application_permission_denied:
    "Die lokale Berechtigung für diese sichere Prüfung fehlt.",
};

function evidenceSubject(value: {
  credential: string | null;
  path: string | null;
  privilege: string | null;
}): string {
  return [value.credential, value.path, value.privilege]
    .filter((part): part is string => part !== null)
    .join(" · ");
}

export function issueMessage(issue: OnboardingIssue): string {
  const subject = evidenceSubject(issue);
  const message =
    issueLabels[issue.code] ??
    "Die Remote-Prüfung meldet einen geschlossenen Sicherheitsfehler.";
  return subject === "" ? message : `${message} (${subject})`;
}

export function warningMessage(warning: OnboardingWarning): string {
  const subject = evidenceSubject(warning);
  const message =
    issueLabels[warning.code] ??
    "Die Remote-Prüfung meldet eine zusätzliche Read-only-Abweichung.";
  return subject === "" ? message : `${message} (${subject})`;
}

export function verificationLabel(value: string): string {
  return (
    {
      tls_verified: "TLS-Vertrauen bestätigt",
      product_supported: "Produkt und Version unterstützt",
      scan_permissions_verified: "Scanner-Rechte bestätigt",
      backup_permissions_verified: "Backup-Rechte bestätigt",
      connection_activated: "Verbindung aktiviert",
      first_automatic_scan_pending:
        "Erster automatischer Inventarlauf ausstehend",
      inventory_verified: "Automatisches Inventar bestätigt",
      inventory_partial: "Automatisches Inventar teilweise verfügbar",
      inventory_failed: "Automatisches Inventar fehlgeschlagen",
      failed: "Prüfung fehlgeschlagen",
      not_activated: "Nicht aktiviert",
      not_started: "Noch nicht gestartet",
    }[value] ?? "Unbekannter Zustand – sicherheitshalber nicht bestätigt"
  );
}

export function verificationSeverity(
  value: string,
): "success" | "warn" | "danger" | "secondary" {
  if (
    [
      "tls_verified",
      "product_supported",
      "scan_permissions_verified",
      "backup_permissions_verified",
      "connection_activated",
      "inventory_verified",
    ].includes(value)
  ) {
    return "success";
  }
  if (["first_automatic_scan_pending", "inventory_partial"].includes(value)) {
    return "warn";
  }
  if (["failed", "inventory_failed", "not_activated"].includes(value)) {
    return "danger";
  }
  return "secondary";
}

export function inventoryStatusDescription(value: string | null): string {
  return (
    {
      first_automatic_scan_pending:
        "Der verifizierte Zustand wartet auf den nächsten regulären Collector-Rasterpunkt.",
      inventory_verified:
        "Der letzte reguläre Collector-Lauf hat das Inventar vollständig bestätigt.",
      inventory_partial:
        "Der letzte reguläre Collector-Lauf konnte das Inventar nur teilweise bestätigen.",
      inventory_failed:
        "Der letzte reguläre Collector-Lauf konnte das Inventar nicht bestätigen.",
    }[value ?? ""] ??
    "Für diese Verbindung liegt kein atomar verifizierter Onboarding-Nachweis vor."
  );
}

function normalizedFingerprint(value: string): string {
  return value.replace(/[:\s-]/g, "").toLowerCase();
}

export function useConnectionOnboarding() {
  const step = ref(1);
  const mode = ref<OnboardingMode>("activate");
  const connectionId = ref<string | null>(null);
  const endpointId = ref<string | null>(null);
  const expectedRevision = ref(0);
  const product = ref<OnboardingProduct>("pve");
  const form = reactive({
    displayName: "",
    host: "",
    port: 8006,
    tlsMode: "system_ca" as OnboardingTlsMode,
    customCaPem: "",
    sha256Fingerprint: "",
    scanTokenId: canonicalTokenIds.pve.scan as string,
    scanTokenSecret: "",
    backupTokenId: canonicalTokenIds.pve.backup as string,
    backupTokenSecret: "",
  });
  const requiresBackup = computed(() => product.value === "pve");
  const tokenIdsValid = computed(
    () =>
      form.scanTokenId === canonicalTokenIds[product.value].scan &&
      (!requiresBackup.value ||
        form.backupTokenId === canonicalTokenIds.pve.backup),
  );
  const fingerprint = computed(() =>
    normalizedFingerprint(form.sha256Fingerprint),
  );
  const trustValid = computed(
    () =>
      (form.tlsMode === "system_ca" &&
        form.customCaPem === "" &&
        form.sha256Fingerprint === "") ||
      (form.tlsMode === "custom_ca" &&
        form.customCaPem.includes("-----BEGIN CERTIFICATE-----") &&
        form.customCaPem.includes("-----END CERTIFICATE-----") &&
        form.sha256Fingerprint === "") ||
      (form.tlsMode === "sha256_fingerprint" &&
        /^[0-9a-f]{64}$/.test(fingerprint.value) &&
        form.customCaPem === ""),
  );
  const endpointValid = computed(
    () =>
      form.displayName.trim() !== "" &&
      form.host.trim() !== "" &&
      form.port >= 1 &&
      form.port <= 65535 &&
      trustValid.value,
  );
  const credentialsValid = computed(
    () =>
      tokenIdsValid.value &&
      form.scanTokenSecret !== "" &&
      (!requiresBackup.value || form.backupTokenSecret !== ""),
  );

  function selectProduct(value: OnboardingProduct): void {
    mode.value = "activate";
    connectionId.value = null;
    endpointId.value = null;
    expectedRevision.value = 0;
    product.value = value;
    form.port = value === "pve" ? 8006 : 8007;
    form.scanTokenId = canonicalTokenIds[value].scan;
    form.backupTokenId = canonicalTokenIds.pve.backup;
    clearSecrets();
    step.value = 1;
  }

  function prepareConnectionOperation(
    operation: Exclude<OnboardingMode, "activate">,
    seed: OnboardingConnectionSeed,
  ): void {
    mode.value = operation;
    connectionId.value = seed.connectionId;
    endpointId.value = seed.endpointId;
    expectedRevision.value = seed.revision;
    product.value = seed.product;
    form.displayName = seed.displayName;
    form.host = seed.endpoint?.host ?? "";
    form.port = seed.endpoint?.port ?? (seed.product === "pve" ? 8006 : 8007);
    form.tlsMode = seed.endpoint?.tlsMode ?? "system_ca";
    form.customCaPem = seed.endpoint?.customCaPem ?? "";
    form.sha256Fingerprint = seed.endpoint?.sha256Fingerprint ?? "";
    form.scanTokenId = canonicalTokenIds[seed.product].scan;
    form.backupTokenId = canonicalTokenIds.pve.backup;
    clearSecrets();
    step.value = 1;
  }

  function selectTlsMode(value: OnboardingTlsMode): void {
    form.tlsMode = value;
    if (value !== "custom_ca") form.customCaPem = "";
    if (value !== "sha256_fingerprint") form.sha256Fingerprint = "";
  }

  function clearSecrets(): void {
    form.scanTokenSecret = "";
    form.backupTokenSecret = "";
  }

  function requestFields() {
    return {
      displayName: form.displayName.trim(),
      endpoint: {
        host: form.host.trim(),
        port: form.port,
        tlsMode: form.tlsMode,
        customCaPem: form.tlsMode === "custom_ca" ? form.customCaPem : null,
        sha256Fingerprint:
          form.tlsMode === "sha256_fingerprint" ? fingerprint.value : null,
      },
    };
  }

  function scanCredential() {
    return {
      tokenId: form.scanTokenId,
      tokenSecret: form.scanTokenSecret,
    };
  }

  function backupCredential() {
    return {
      tokenId: form.backupTokenId,
      tokenSecret: form.backupTokenSecret,
    };
  }

  function activationRequest(): OnboardingActivationRequest {
    const fields = requestFields();
    return product.value === "pve"
      ? {
          ...fields,
          expectedRevision: 0,
          product: "pve",
          credentials: {
            scan: scanCredential(),
            backup: backupCredential(),
          },
        }
      : {
          ...fields,
          expectedRevision: 0,
          product: "pbs",
          credentials: { scan: scanCredential() },
        };
  }

  function endpointMutationRequest(): OnboardingEndpointMutationRequest {
    const fields = requestFields();
    return product.value === "pve"
      ? {
          ...fields,
          expectedRevision: expectedRevision.value,
          product: "pve",
          credentials: {
            scan: scanCredential(),
            backup: backupCredential(),
          },
        }
      : {
          ...fields,
          expectedRevision: expectedRevision.value,
          product: "pbs",
          credentials: { scan: scanCredential() },
        };
  }

  function rotationRequest(): OnboardingRotationRequest {
    if (endpointId.value === null) {
      throw new Error("A selected endpoint is required for token rotation.");
    }
    const request = endpointMutationRequest();
    return request.product === "pve"
      ? { ...request, endpointId: endpointId.value }
      : { ...request, endpointId: endpointId.value };
  }

  return {
    step,
    mode,
    connectionId,
    endpointId,
    expectedRevision,
    product,
    form,
    requiresBackup,
    tokenIdsValid,
    trustValid,
    endpointValid,
    credentialsValid,
    selectProduct,
    prepareConnectionOperation,
    selectTlsMode,
    clearSecrets,
    activationRequest,
    endpointMutationRequest,
    rotationRequest,
  };
}
