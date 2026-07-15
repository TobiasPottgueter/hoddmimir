import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type {
  BackupTargetCandidate,
  ConfiguredBackupTarget,
  TargetCommandRequest,
} from "@/api/generated/types.gen";
import BackupTargetForm from "@/components/targets/BackupTargetForm.vue";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useBackupTargetsStore } from "@/stores/backupTargets";
import { useAuthStore } from "@/stores/auth";
import { useConfigurationCommandsStore } from "@/stores/configurationCommands";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";
import BackupTargetsView from "./BackupTargetsView.vue";

const candidate = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageName: "backup",
  storageType: "dir",
  shared: true,
  inventoryState: "active",
  observedAt: "2026-07-12T10:00:00.000000Z",
  canEnable: false,
  executor: {
    status: "requires_target_configuration",
    targetCount: 0,
    expectedNodeCount: 0,
    observedNodeCount: 0,
    vmBackupAuthorized: null,
    datastoreAllocateAuthorized: null,
    authorized: null,
    freshness: "missing",
    observedAt: null,
    blockers: ["executor_evidence_missing"],
  },
  nodes: [],
  pbs: null,
  blockers: ["storage_inventory_evidence_stale", "no_active_node"],
} satisfies BackupTargetCandidate;

const configuredTarget = {
  id: UUID,
  revision: 2,
  enabled: false,
  displayName: "Primärziel",
  connectionId: UUID,
  connectionName: "PVE Produktion",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageId: UUID,
  storageName: "pbs-primary",
  storageType: "pbs",
  minimumFreeBytes: "10000",
  fixedParallelLimit: 2,
  pbsConnectionId: UUID,
  pbsDatastoreId: OTHER_UUID,
  pbsNamespaceId: null,
  disabledAt: null,
  allowedNodes: [{ id: OTHER_UUID, name: "pve-a" }],
  canEnable: false,
  blockers: ["minimum_free_unconfigured"],
} satisfies ConfiguredBackupTarget;

describe("BackupTargetsView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt fail-closed Kandidaten, Filter und Cursorpagination ohne Schreibaktionen", async () => {
    const store = useBackupTargetsStore();
    const configuredStore = useConfiguredBackupTargetsStore();
    store.items = [candidate];
    store.page = { limit: 20, count: 1, hasMore: true, nextCursor: "next" };
    configuredStore.items = [configuredTarget];
    configuredStore.page = {
      limit: 20,
      count: 1,
      hasMore: true,
      nextCursor: "configured-next",
    };
    const load = vi.spyOn(store, "load").mockResolvedValue();
    const loadMore = vi.spyOn(store, "loadMore").mockResolvedValue();
    const configuredLoad = vi
      .spyOn(configuredStore, "load")
      .mockResolvedValue();
    const configuredLoadMore = vi
      .spyOn(configuredStore, "loadMore")
      .mockResolvedValue();
    const wrapper = mount(BackupTargetsView, {
      global: { plugins: [PrimeVue] },
    });

    expect(load).toHaveBeenCalledOnce();
    expect(configuredLoad).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain("Konfigurierte Backupziele");
    expect(wrapper.text()).toContain("Primärziel");
    expect(wrapper.text()).toContain("Backupziel-Kandidaten");
    expect(wrapper.text()).toContain("Nicht aktivierbar");
    expect(wrapper.text()).toContain("Exakt fünf Minuten");
    expect(wrapper.text()).not.toMatch(
      /jetzt scannen|backup starten|speichern|aktivieren|stoppen|abbrechen/i,
    );

    await wrapper.get(".configured-target-pagination button").trigger("click");
    expect(configuredLoadMore).toHaveBeenCalledOnce();
    await wrapper.get(".target-pagination button").trigger("click");
    expect(loadMore).toHaveBeenCalledOnce();

    const candidateForm = wrapper.get(
      'form[aria-label="Backupziel-Kandidaten filtern"]',
    );
    const fields = candidateForm.findAll("input");
    await fields[0]?.setValue(` ${UUID} `);
    await fields[1]?.setValue(` ${OTHER_UUID} `);
    await candidateForm.trigger("submit");
    expect(store.connectionId).toBe(UUID);
    expect(store.clusterId).toBe(OTHER_UUID);
    expect(store.page.nextCursor).toBeNull();
    expect(load).toHaveBeenCalledTimes(2);

    const configuredForm = wrapper.get(
      'form[aria-label="Konfigurierte Backupziele filtern"]',
    );
    await configuredForm.get("input").setValue("  Primär  ");
    await configuredForm.trigger("submit");
    expect(configuredStore.search).toBe("Primär");
    expect(configuredLoad).toHaveBeenCalledTimes(2);
  });

  it.each([
    [true, null, "Daten werden geladen"],
    [
      false,
      "Die Backupziel-Projektion ist vorübergehend nicht verfügbar.",
      "vorübergehend nicht verfügbar",
    ],
    [false, null, "Keine Backupziel-Kandidaten"],
  ] as const)(
    "zeigt Loading-, Fehler- und Empty-Zustand",
    (loading, error, expected) => {
      const store = useBackupTargetsStore();
      const configuredStore = useConfiguredBackupTargetsStore();
      store.loading = loading;
      store.error = error;
      store.items = [];
      vi.spyOn(store, "load").mockImplementation(
        () => new Promise<void>(() => undefined),
      );
      vi.spyOn(configuredStore, "load").mockImplementation(
        () => new Promise<void>(() => undefined),
      );
      const wrapper = mount(BackupTargetsView, {
        global: { plugins: [PrimeVue] },
      });
      expect(wrapper.text()).toContain(expected);
    },
  );

  it("zeigt Management-Controls und serverseitige Konfliktzustände nur mit Permission", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useBackupTargetsStore();
    const configuredStore = useConfiguredBackupTargetsStore();
    store.items = [candidate];
    configuredStore.items = [
      { ...configuredTarget, canEnable: true, blockers: [] },
    ];
    vi.spyOn(store, "load").mockResolvedValue();
    vi.spyOn(configuredStore, "load").mockResolvedValue();
    const commands = useConfigurationCommandsStore();
    const saveTarget = vi.spyOn(commands, "saveTarget").mockResolvedValue(true);
    const setTargetEnabled = vi
      .spyOn(commands, "setTargetEnabled")
      .mockResolvedValue(true);
    commands.error =
      "Die Konfiguration wurde zwischenzeitlich geändert. Lade den aktuellen Serverstand neu.";
    commands.conflictRevision = 7;

    const wrapper = mount(BackupTargetsView, {
      global: { plugins: [PrimeVue] },
    });
    expect(wrapper.text()).toContain("Backupziel anlegen");
    expect(wrapper.text()).toContain("Aktuelle Serverrevision: 7");
    expect(wrapper.text()).toContain("Aktivieren");

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Serverstand laden"))
      ?.trigger("click");
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Backupziel anlegen"))
      ?.trigger("click");
    await flushPromises();
    expect(wrapper.findComponent(BackupTargetForm).exists()).toBe(true);
    await wrapper.findComponent(BackupTargetForm).vm.$emit("cancel");

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Als Ziel konfigurieren"))
      ?.trigger("click");
    await wrapper.findComponent(BackupTargetForm).vm.$emit("cancel");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Bearbeiten")
      ?.trigger("click");
    const body = {
      expectedRevision: 2,
      displayName: "Primärziel",
      connectionId: UUID,
      clusterId: OTHER_UUID,
      storageId: UUID,
      minimumFreeBytes: null,
      fixedParallelLimit: null,
      pbsConnectionId: null,
      pbsDatastoreId: null,
      pbsNamespaceId: null,
      allowedNodeIds: [],
    } satisfies TargetCommandRequest;
    wrapper.findComponent(BackupTargetForm).vm.$emit("submit", body, UUID);
    await flushPromises();
    expect(saveTarget).toHaveBeenCalledWith(body, UUID);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Aktivieren")
      ?.trigger("click");
    await flushPromises();
    expect(setTargetEnabled).toHaveBeenCalled();
  });
});
