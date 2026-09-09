import { createPinia, setActivePinia } from "pinia";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import InputNumber from "primevue/inputnumber";
import Select from "primevue/select";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useAuthStore } from "@/stores/auth";
import { useConnectionOnboardingStore } from "@/stores/connectionOnboarding";
import ConnectionOnboardingWizard from "./ConnectionOnboardingWizard.vue";

const UUID = "00112233-4455-6677-8899-aabbccddeeff";
const guidance = (product: "pve" | "pbs") => ({
  product,
  commands: [
    {
      id: `${product}-user-check`,
      command: `pveum user list --output-format json`,
      purpose: "Bestehenden Benutzer prüfen",
      mutatesRemote: false,
      containsSecret: false,
    },
    {
      id: `${product}-token-create`,
      command: `pveum user token add hoddmimir@${product} scan --privsep 1`,
      purpose: "Scanner-Token erzeugen",
      mutatesRemote: true,
      containsSecret: false,
    },
  ],
  warnings: [
    "Secrets nur aus dem unmittelbaren Erzeugungsresultat übernehmen.",
  ],
});
const activation = {
  status: "applied",
  connectionId: UUID,
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
    warnings: [
      {
        code: "additional_read_only_permission",
        path: "/",
        privilege: "Sys.Audit",
      },
      { code: "additional_read_only_permission", path: "/", privilege: null },
    ],
  },
};
const endpointVerification = {
  ...activation,
  revision: 3,
  onboardingStatus: "endpoint_verified",
  verification: {
    ...activation.verification,
    inventory: "first_automatic_scan_pending",
  },
};
const managedConnection = {
  id: UUID,
  displayName: "PVE Managed",
  product: "pve" as const,
  enabled: true,
  revision: 2,
  detectedVersion: "9.0",
  versionSupportStatus: "supported" as const,
  createdAt: "2026-07-13T00:00:00.000000Z",
  updatedAt: "2026-07-13T00:00:00.000000Z",
  onboardingState: null,
  endpoints: [
    {
      id: "11112233-4455-6677-8899-aabbccddeeff",
      host: "pve.example.test",
      port: 8006,
      priority: 100,
      enabled: true,
      tlsMode: "system_ca" as const,
      customCaConfigured: false,
      sha256Fingerprint: null,
      lastAttemptedAt: null,
      lastSuccessAt: null,
      lastErrorCode: null,
    },
  ],
  credentials: [],
};

function response(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function label(wrapper: VueWrapper, text: string) {
  return wrapper
    .findAll("label")
    .find((item) => item.find("span").text().includes(text))!;
}

function button(wrapper: VueWrapper, text: string) {
  return wrapper.findAll("button").find((item) => item.text().includes(text))!;
}

async function reachCredentials(wrapper: VueWrapper): Promise<void> {
  await button(wrapper, "Endpoint konfigurieren").trigger("click");
  await label(wrapper, "Anzeigename").find("input").setValue("QA PVE");
  await label(wrapper, "DNS-Name").find("input").setValue("pve.example.test");
  await button(wrapper, "Token eintragen").trigger("click");
}

describe("ConnectionOnboardingWizard", () => {
  beforeEach(() => {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().$patch({
      initialized: true,
      csrfToken: "csrf",
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
    });
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
  });
  afterEach(() => vi.unstubAllGlobals());

  it("renders safe commands and completes the PVE flow with emptied secrets", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(response(guidance("pve")))
      .mockResolvedValueOnce(response(activation));
    vi.stubGlobal("fetch", fetchMock);
    const wrapper = mount(ConnectionOnboardingWizard, {
      props: { connection: null },
      global: { plugins: [PrimeVue] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain("sichere Einzelbefehle");
    expect(wrapper.text()).toContain("Bestehenden Benutzer prüfen");
    expect(wrapper.text()).not.toContain("Jetzt scannen");
    expect(wrapper.text()).not.toContain("Testbackup");
    await button(wrapper, "Befehl kopieren").trigger("click");
    await flushPromises();
    expect(navigator.clipboard.writeText).toHaveBeenCalled();
    expect(wrapper.text()).toContain("Kopiert");

    await reachCredentials(wrapper);
    await label(wrapper, "Scanner-Token-ID")
      .find("input")
      .setValue("hoddmimir@pve!scan");
    await label(wrapper, "Backup-Token-ID")
      .find("input")
      .setValue("hoddmimir@pve!backup");
    await label(wrapper, "Scanner-Secret")
      .find('input[type="password"]')
      .setValue("scan-secret");
    await label(wrapper, "Backup-Secret")
      .find('input[type="password"]')
      .setValue("backup-secret");
    await button(wrapper, "Prüfung vorbereiten").trigger("click");
    await button(wrapper, "Sicher prüfen und aktivieren").trigger("click");
    await flushPromises();

    expect(wrapper.emitted("completed")).toBeUndefined();
    expect(wrapper.text()).toContain("Zusätzliche Read-only-Rechte");
    expect(wrapper.text()).toContain("Sys.Audit");
    expect(wrapper.text()).not.toContain("scan-secret");
    expect(wrapper.text()).not.toContain("backup-secret");
    const submitted = await (fetchMock.mock.calls[1]![0] as Request)
      .clone()
      .json();
    expect(submitted.credentials.scan.tokenId).toBe("hoddmimir@pve!scan");
    expect(submitted.credentials.backup.tokenId).toBe("hoddmimir@pve!backup");
    await button(wrapper, "Onboarding abschließen").trigger("click");
    expect(wrapper.emitted("completed")).toEqual([[UUID]]);
  });

  it("shows path-specific verification failures and still clears secrets", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValueOnce(response(guidance("pve")))
        .mockResolvedValueOnce(
          response(
            {
              error: {
                code: "onboarding_verification_failed",
                issues: [
                  {
                    code: "forbidden_permission_present",
                    credential: "backup",
                    path: "/vms",
                    privilege: "VM.PowerMgmt",
                  },
                ],
                verification: activation.verification,
              },
            },
            422,
          ),
        ),
    );
    const wrapper = mount(ConnectionOnboardingWizard, {
      global: { plugins: [PrimeVue] },
    });
    await flushPromises();
    await reachCredentials(wrapper);
    await label(wrapper, "Scanner-Secret").find("input").setValue("wrong");
    await label(wrapper, "Backup-Secret").find("input").setValue("backup");
    await button(wrapper, "Prüfung vorbereiten").trigger("click");
    await button(wrapper, "Sicher prüfen und aktivieren").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain(
      "schreibendes oder administratives Zusatzrecht",
    );
    expect(wrapper.text()).toContain("/vms");
    expect(wrapper.text()).toContain("Erster automatischer Inventarlauf");
    expect(wrapper.emitted("completed")).toBeUndefined();
  });

  it("switches to PBS, enforces exclusive TLS material and emits cancel", async () => {
    const pbsActivation = {
      ...activation,
      verification: {
        ...activation.verification,
        backupPermissions: null,
        detectedProduct: "pbs",
        detectedVersion: "4.0",
      },
    };
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(response(guidance("pve")))
      .mockResolvedValueOnce(response(guidance("pbs")))
      .mockResolvedValueOnce(response(pbsActivation));
    vi.stubGlobal("fetch", fetchMock);
    const wrapper = mount(ConnectionOnboardingWizard, {
      global: { plugins: [PrimeVue] },
    });
    await flushPromises();
    const selects = wrapper.findAllComponents(Select);
    selects[0]!.vm.$emit("update:modelValue", "pbs");
    await flushPromises();
    expect(wrapper.text()).toContain("Scanner-Token erzeugen");
    await button(wrapper, "Endpoint konfigurieren").trigger("click");
    wrapper.findComponent(InputNumber).vm.$emit("update:modelValue", 8007);
    await wrapper.vm.$nextTick();
    wrapper
      .findAllComponents(Select)[0]!
      .vm.$emit("update:modelValue", "custom_ca");
    await wrapper.vm.$nextTick();
    await label(wrapper, "CA-Zertifikat").find("textarea").setValue("PEM");
    wrapper
      .findAllComponents(Select)[0]!
      .vm.$emit("update:modelValue", "sha256_fingerprint");
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("SHA-256-Fingerprint");
    expect(wrapper.text()).not.toContain("CA-Zertifikat (PEM)");
    await label(wrapper, "SHA-256-Fingerprint")
      .find("input")
      .setValue("AA:".repeat(31) + "AA");
    await label(wrapper, "Anzeigename").find("input").setValue("QA PBS");
    await label(wrapper, "DNS-Name").find("input").setValue("pbs.example.test");
    await button(wrapper, "Token eintragen").trigger("click");
    await label(wrapper, "Scanner-Token-ID").find("input").setValue("wrong");
    expect(wrapper.text()).toContain("Token-IDs müssen kanonisch");
    await label(wrapper, "Scanner-Token-ID")
      .find("input")
      .setValue("hoddmimir@pbs!scan");
    await button(wrapper, "Zurück").trigger("click");
    expect(wrapper.text()).toContain("Endpoint & TLS");
    await button(wrapper, "Token eintragen").trigger("click");
    await label(wrapper, "Scanner-Secret").find("input").setValue("pbs-secret");
    await button(wrapper, "Prüfung vorbereiten").trigger("click");
    await button(wrapper, "Sicher prüfen und aktivieren").trigger("click");
    await flushPromises();
    expect(wrapper.text()).not.toContain("Backup-Rechte");
    expect(wrapper.text()).not.toContain("pbs-secret");
    const store = useConnectionOnboardingStore();
    store.error = "Die Verbindung wurde zwischenzeitlich geändert.";
    store.conflictRevision = 7;
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Aktuelle Revision: 7");
    await button(wrapper, "Abbrechen").trigger("click");
    expect(wrapper.emitted("cancel")).toHaveLength(1);
  });

  it("rotates the complete credential set only for an explicitly selected endpoint", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(response(guidance("pve")))
      .mockResolvedValueOnce(
        response({ ...activation, status: "replayed", revision: 3 }),
      );
    vi.stubGlobal("fetch", fetchMock);
    const wrapper = mount(ConnectionOnboardingWizard, {
      props: {
        operation: "rotate",
        connection: {
          id: UUID,
          displayName: "PVE Rotation",
          product: "pve",
          enabled: true,
          revision: 2,
          detectedVersion: "9.0",
          versionSupportStatus: "supported",
          createdAt: "2026-07-13T00:00:00.000000Z",
          updatedAt: "2026-07-13T00:00:00.000000Z",
          onboardingState: null,
          endpoints: [
            {
              id: "11112233-4455-6677-8899-aabbccddeeff",
              host: "pve.example.test",
              port: 8006,
              priority: 100,
              enabled: true,
              tlsMode: "custom_ca",
              customCaConfigured: true,
              sha256Fingerprint: null,
              lastAttemptedAt: null,
              lastSuccessAt: null,
              lastErrorCode: null,
            },
            {
              id: "22222233-4455-6677-8899-aabbccddeeff",
              host: "future.example.test",
              port: 8443,
              priority: 200,
              enabled: true,
              tlsMode: "future_tls_mode" as never,
              customCaConfigured: false,
              sha256Fingerprint: null,
              lastAttemptedAt: null,
              lastSuccessAt: null,
              lastErrorCode: null,
            },
          ],
          credentials: [],
        },
      },
      global: { plugins: [PrimeVue] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain("Zugangsdaten sicher rotieren");
    await button(wrapper, "Endpoint konfigurieren").trigger("click");
    expect(wrapper.text()).toContain("Endpoint bewusst auswählen");
    wrapper
      .findAllComponents(Select)[0]!
      .vm.$emit("update:modelValue", "unknown-endpoint");
    wrapper
      .findAllComponents(Select)[0]!
      .vm.$emit("update:modelValue", "11112233-4455-6677-8899-aabbccddeeff");
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("CA-Zertifikat wird nicht zurückgelesen");
    await label(wrapper, "CA-Zertifikat")
      .find("textarea")
      .setValue("-----BEGIN CERTIFICATE-----\nQA\n-----END CERTIFICATE-----");
    expect(
      label(wrapper, "Anzeigename").find("input").attributes(),
    ).toHaveProperty("disabled");
    await button(wrapper, "Token eintragen").trigger("click");
    await label(wrapper, "Scanner-Secret").find("input").setValue("new-scan");
    await label(wrapper, "Backup-Secret").find("input").setValue("new-backup");
    await button(wrapper, "Prüfung vorbereiten").trigger("click");
    await button(wrapper, "Sicher prüfen und rotieren").trigger("click");
    await flushPromises();
    const rotationRequest = fetchMock.mock.calls[1]![0] as Request;
    expect(rotationRequest.url).toContain(
      `/api/v1/connections/${UUID}/onboarding/rotate`,
    );
    const submitted = await rotationRequest.clone().json();
    expect(submitted).toMatchObject({
      expectedRevision: 2,
      endpointId: "11112233-4455-6677-8899-aabbccddeeff",
      credentials: {
        scan: { tokenSecret: "new-scan" },
        backup: { tokenSecret: "new-backup" },
      },
    });
    expect(wrapper.text()).toContain("Credentials sicher rotiert");
    expect(wrapper.text()).not.toContain("new-scan");
    await button(wrapper, "Rotation abschließen").trigger("click");
    expect(wrapper.emitted("completed")).toEqual([[UUID]]);
  });

  it.each([
    {
      operation: "endpoint_add" as const,
      endpoint: null,
      title: "Verifizierten Failover-Endpoint hinzufügen",
      host: "pve-failover.qa.invalid",
      method: "POST",
      suffix: "/onboarding/endpoints",
    },
    {
      operation: "endpoint_update" as const,
      endpoint: managedConnection.endpoints[0]!,
      title: "Endpoint sicher ändern",
      host: "pve-updated.qa.invalid",
      method: "PUT",
      suffix: "/onboarding/endpoints/11112233-4455-6677-8899-aabbccddeeff",
    },
  ])(
    "verifies $operation with the full credential set before persisting",
    async ({ operation, endpoint, title, host, method, suffix }) => {
      const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(guidance("pve")))
        .mockResolvedValueOnce(response(endpointVerification));
      vi.stubGlobal("fetch", fetchMock);
      const wrapper = mount(ConnectionOnboardingWizard, {
        props: { operation, connection: managedConnection, endpoint },
        global: { plugins: [PrimeVue] },
      });
      await flushPromises();
      expect(wrapper.text()).toContain(title);
      await button(wrapper, "Endpoint konfigurieren").trigger("click");
      await label(wrapper, "DNS-Name").find("input").setValue(host);
      await button(wrapper, "Token eintragen").trigger("click");
      await label(wrapper, "Scanner-Secret").find("input").setValue("scan");
      await label(wrapper, "Backup-Secret").find("input").setValue("backup");
      await button(wrapper, "Prüfung vorbereiten").trigger("click");
      await button(wrapper, "Sicher prüfen und Endpoint speichern").trigger(
        "click",
      );
      await flushPromises();
      const request = fetchMock.mock.calls[1]![0] as Request;
      expect(request.url).toContain(`/api/v1/connections/${UUID}${suffix}`);
      expect(request.method).toBe(method);
      expect(wrapper.text()).toContain(
        "Endpoint sicher geprüft und gespeichert",
      );
      expect(wrapper.text()).toContain(
        "nächste automatische Inventarlauf ist ausstehend",
      );
      await button(wrapper, "Endpoint-Einrichtung abschließen").trigger(
        "click",
      );
      expect(wrapper.emitted("completed")).toEqual([[UUID]]);
    },
  );
});
