import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ConnectionDetail } from "@/api/generated/types.gen";
import { UUID, OTHER_UUID } from "@/test/fixtures";
import { useAuthStore } from "@/stores/auth";
import { useConnectionsStore } from "@/stores/connections";
import { useConnectionOnboardingStore } from "@/stores/connectionOnboarding";
import ConnectionOnboardingWizard from "@/components/connections/ConnectionOnboardingWizard.vue";
import ConnectionsView from "./ConnectionsView.vue";

const detail: ConnectionDetail = {
  id: UUID,
  displayName: "PVE Produktion",
  product: "pve",
  enabled: false,
  revision: 2,
  detectedVersion: "8.4.1",
  versionSupportStatus: "supported",
  createdAt: "2026-07-13T00:00:00.000000Z",
  updatedAt: "2026-07-13T00:00:00.000000Z",
  onboardingState: null,
  endpoints: [
    {
      id: OTHER_UUID,
      host: "pve.example.test",
      port: 8006,
      priority: 100,
      enabled: true,
      tlsMode: "system_ca",
      customCaConfigured: false,
      sha256Fingerprint: null,
      lastAttemptedAt: null,
      lastSuccessAt: "2026-07-13T00:01:00.000000Z",
      lastErrorCode: null,
    },
    {
      id: "33332233-4455-6677-8899-aabbccddeeff",
      host: "pve-failover.example.test",
      port: 8006,
      priority: 200,
      enabled: true,
      tlsMode: "system_ca",
      customCaConfigured: false,
      sha256Fingerprint: null,
      lastAttemptedAt: null,
      lastSuccessAt: null,
      lastErrorCode: null,
    },
  ],
  credentials: [
    {
      purpose: "collector",
      principal: "reader@pve",
      tokenName: "collector",
      configured: true,
      revision: 1,
      rotatedAt: "2026-07-13T00:00:00.000000Z",
      updatedAt: "2026-07-13T00:00:00.000000Z",
    },
  ],
};
const verifiedDetail = {
  ...detail,
  onboardingState: {
    status: "inventory_verified" as const,
    verifiedAt: "2026-07-13T00:00:00.000000Z",
    inventoryStatusChangedAt: "2026-07-13T00:01:00.000000Z",
    lastInventoryRunId: "44442233-4455-6677-8899-aabbccddeeff",
  },
};
describe("ConnectionsView", () => {
  beforeEach(() => setActivePinia(createPinia()));
  it("zeigt ohne Permission nur den geschlossenen Berechtigungszustand", async () => {
    const store = useConnectionsStore();
    vi.spyOn(store, "load").mockResolvedValue();
    const wrapper = mount(ConnectionsView, {
      global: { plugins: [PrimeVue], stubs: { teleport: true } },
    });
    expect(wrapper.text()).toContain("backup_configuration.manage");
    expect(wrapper.text()).not.toContain("Verbindung anlegen");
  });
  it("bedient Connection-, Endpoint- und Credential-Commands ohne Secret-Rücklesen", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useConnectionsStore();
    store.items = [verifiedDetail];
    const load = vi.spyOn(store, "load").mockResolvedValue();
    const onboarding = useConnectionOnboardingStore();
    onboarding.guidance = { product: "pve", commands: [], warnings: [] };
    vi.spyOn(onboarding, "loadGuidance").mockResolvedValue(true);
    const update = vi.spyOn(store, "update").mockResolvedValue(true);
    const disable = vi.spyOn(store, "disable").mockResolvedValue(true);
    const disableEndpoint = vi
      .spyOn(store, "disableEndpoint")
      .mockResolvedValue(true);
    const wrapper = mount(ConnectionsView, {
      attachTo: document.body,
      global: { plugins: [PrimeVue], stubs: { teleport: true } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain("PVE Produktion");
    expect(wrapper.text()).toContain("Proxmox VE");
    expect(wrapper.text()).toContain("8.4.1 · Unterstützt");
    expect(wrapper.text()).toContain("System-CA");
    expect(wrapper.text()).toContain("13.07.2026");
    expect(wrapper.text()).toContain("Automatisches Inventar bestätigt");
    expect(wrapper.text()).toContain(
      "letzte reguläre Collector-Lauf hat das Inventar vollständig bestätigt",
    );
    expect(wrapper.text()).toContain("Sicher geprüft (UTC)");
    expect(wrapper.text()).toContain("Inventarstatus geändert (UTC)");
    expect(wrapper.text()).toContain("44442233-4455-6677-8899-aabbccddeeff");
    expect(
      wrapper
        .findAll("button")
        .filter((button) => button.text() === "Deaktivieren")
        .slice(-2)
        .every((button) => button.attributes("disabled") === undefined),
    ).toBe(true);
    expect(wrapper.text()).toContain("Konfiguriert · write-only");
    expect(wrapper.text()).not.toContain("high-entropy");
    await wrapper
      .findAll("button")
      .find((b) => b.text().includes("Verbindung anlegen"))!
      .trigger("click");
    expect(wrapper.text()).toContain("Neue Proxmox-Verbindung");
    expect(wrapper.text()).toContain("keine Benutzer, Rollen, Token oder ACLs");
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Name bearbeiten")!
      .trigger("click");
    await wrapper.findAll("form.administration-form").at(-1)!.trigger("submit");
    await flushPromises();
    expect(update).toHaveBeenCalledWith(UUID, {
      expectedRevision: 2,
      displayName: "PVE Produktion",
    });
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Name bearbeiten")!
      .trigger("click");
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Erneut sicher aktivieren")!
      .trigger("click");
    expect(wrapper.text()).toContain("Proxmox-Verbindung erneut sicher prüfen");
    expect(disable).not.toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Verifizierten Endpoint hinzufügen")!
      .trigger("click");
    expect(wrapper.text()).toContain(
      "Verifizierten Failover-Endpoint hinzufügen",
    );
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    store.items = [{ ...detail, revision: 7 }];
    await wrapper.vm.$nextTick();
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Bearbeiten")!
      .trigger("click");
    expect(wrapper.text()).toContain("Endpoint sicher ändern");
    const endpointWizard = wrapper
      .findAllComponents(ConnectionOnboardingWizard)
      .find((component) => component.props("operation") === "endpoint_update");
    expect(endpointWizard).toBeDefined();
    expect(
      (endpointWizard!.props("connection") as ConnectionDetail).revision,
    ).toBe(7);
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Deaktivieren")
      .at(-1)!
      .trigger("click");
    await flushPromises();
    expect(disableEndpoint).toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .find((b) => b.text().includes("Zugangsdaten sicher rotieren"))!
      .trigger("click");
    expect(wrapper.text()).toContain("Proxmox-Verbindung erneut sicher prüfen");
    expect(wrapper.text()).not.toContain("API-Token konfigurieren");
    wrapper
      .findComponent(ConnectionOnboardingWizard)
      .vm.$emit("completed", UUID);
    await flushPromises();
    expect(load).toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .find((b) => b.text().includes("Zugangsdaten sicher rotieren"))!
      .trigger("click");
    await wrapper
      .findAll("button")
      .filter((b) => b.text() === "Abbrechen")
      .at(-1)!
      .trigger("click");
    wrapper.unmount();
  });

  it("zeigt Empty-, Konflikt-, Blocker- und PBS-Zustände vollständig", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useConnectionsStore();
    store.error = "Konflikt";
    store.success = "Gespeichert";
    store.conflictRevision = 9;
    store.blockers = ["last_enabled_endpoint"];
    store.items = [
      {
        ...detail,
        id: OTHER_UUID,
        product: "pbs",
        enabled: true,
        endpoints: [
          {
            ...detail.endpoints[0]!,
            enabled: false,
            customCaConfigured: true,
            tlsMode: "custom_ca",
          },
        ],
        credentials: [],
      },
    ];
    vi.spyOn(store, "load").mockResolvedValue();
    const select = vi.spyOn(store, "select").mockImplementation(async () => {
      store.selected = { ...detail, id: OTHER_UUID, revision: 10 };
    });
    const clear = vi.spyOn(store, "clearResult");
    const disable = vi.spyOn(store, "disable").mockResolvedValue(true);
    const loadMore = vi.spyOn(store, "loadMore").mockResolvedValue();
    store.page = { limit: 20, count: 1, hasMore: true, nextCursor: "next" };
    const wrapper = mount(ConnectionsView, {
      global: { plugins: [PrimeVue], stubs: { teleport: true } },
    });
    expect(wrapper.text()).toContain("Aktuelle Revision: 9");
    expect(wrapper.text()).toContain("last_enabled_endpoint");
    expect(wrapper.text()).toContain("Gespeichert");
    expect(wrapper.text()).not.toContain("Backup-Token");
    expect(wrapper.text()).toContain(
      "Endpoint- und TLS-Änderungen laufen auch bei aktiven Verbindungen",
    );
    const endpointAdd = wrapper
      .findAll("button")
      .find((button) => button.text() === "Verifizierten Endpoint hinzufügen")!;
    expect(endpointAdd.attributes("disabled")).toBeUndefined();
    await wrapper
      .findAll("button")
      .find(
        (button) =>
          button.text() === "Deaktivieren" &&
          button.attributes("disabled") === undefined,
      )!
      .trigger("click");
    await flushPromises();
    expect(disable).toHaveBeenCalledWith(OTHER_UUID, { expectedRevision: 2 });
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Name bearbeiten")!
      .trigger("click");
    store.error = "Konflikt";
    store.conflictRevision = 9;
    await wrapper.vm.$nextTick();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Stand laden"))!
      .trigger("click");
    await flushPromises();
    expect(select).toHaveBeenCalledWith(OTHER_UUID);
    expect(clear).toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Verbindungen"))!
      .trigger("click");
    expect(loadMore).toHaveBeenCalled();
    store.items = [];
    store.loading = true;
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Verbindungen werden geladen");
    store.loading = false;
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Noch keine PVE-/PBS-Verbindung");
  });

  it("zeigt pending, partial, failed und fehlenden Nachweis geschlossen", () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useConnectionsStore();
    store.items = [
      "first_automatic_scan_pending",
      "inventory_partial",
      "inventory_failed",
    ].map((status, index) => ({
      ...detail,
      id: `00000000-0000-4000-8000-00000000000${index}`,
      displayName: `Status ${index}`,
      onboardingState: {
        status: status as
          | "first_automatic_scan_pending"
          | "inventory_partial"
          | "inventory_failed",
        verifiedAt: "2026-07-13T00:00:00.000000Z",
        inventoryStatusChangedAt: "2026-07-13T00:01:00.000000Z",
        lastInventoryRunId: null,
      },
    }));
    store.items.push({ ...detail, id: "00000000-0000-4000-8000-000000000009" });
    vi.spyOn(store, "load").mockResolvedValue();
    const wrapper = mount(ConnectionsView, {
      global: { plugins: [PrimeVue], stubs: { teleport: true } },
    });
    expect(wrapper.text()).toContain(
      "Erster automatischer Inventarlauf ausstehend",
    );
    expect(wrapper.text()).toContain(
      "Automatisches Inventar teilweise verfügbar",
    );
    expect(wrapper.text()).toContain("Automatisches Inventar fehlgeschlagen");
    expect(wrapper.text()).toContain("Nicht verifiziert eingerichtet");
    expect(wrapper.text()).toContain("Noch kein automatischer Lauf");
    expect(wrapper.text()).toContain("Kein Nachweis");
  });
});
