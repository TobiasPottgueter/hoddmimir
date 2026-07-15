import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type {
  ConfiguredPolicy,
  PolicyCommandRequest,
  PolicySelectionEntry,
} from "@/api/generated/types.gen";
import PolicyForm from "@/components/policies/PolicyForm.vue";
import PolicySelectionEditor from "@/components/policies/PolicySelectionEditor.vue";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { usePoliciesStore } from "@/stores/policies";
import { useAuthStore } from "@/stores/auth";
import { useConfigurationCommandsStore } from "@/stores/configurationCommands";
import { useConfigurationInventoryStore } from "@/stores/configurationInventory";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";
import PoliciesView from "./PoliciesView.vue";

const policy = {
  id: UUID,
  revision: 1,
  status: "draft",
  displayName: "Nachtlauf",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  targetId: null,
  targetName: null,
  priority: null,
  mode: null,
  compression: null,
  maximumAgeSeconds: null,
  bytesWrittenThreshold: null,
  cooldownSeconds: null,
  schedule: null,
  desiredRetention: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: [],
  disabledAt: null,
  canEnable: false,
  blockers: ["executor_evidence_missing"],
} satisfies ConfiguredPolicy;
const selection = (
  kind: PolicySelectionEntry["kind"],
): PolicySelectionEntry => ({
  id: kind === "assignment" ? UUID : OTHER_UUID,
  revision: 1,
  status: "active",
  kind,
  scope: kind === "assignment" ? "cluster" : "guest",
  connectionId: UUID,
  clusterId: OTHER_UUID,
  nodeId: null,
  guestId: kind === "guest_override" ? OTHER_UUID : null,
  subjectName: kind === "assignment" ? "cluster-a" : "vm-101",
  selectionValue: "include",
  mode: null,
  compression: null,
  desiredRetention: null,
  disabledAt: null,
});

describe("PoliciesView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt Policy-Liste, paginierte Auswahl und keine Schreibaktionen", async () => {
    const store = usePoliciesStore();
    store.items = [policy];
    store.page = { limit: 20, count: 1, hasMore: true, nextCursor: "next" };
    store.selectedPolicyId = UUID;
    store.selectionItems = [
      selection("assignment"),
      selection("guest_override"),
    ];
    store.selectionPage = {
      limit: 20,
      count: 2,
      hasMore: true,
      nextCursor: "sel-next",
    };
    vi.spyOn(store, "load").mockResolvedValue();
    vi.spyOn(
      useConfiguredBackupTargetsStore(),
      "loadAllForConfiguration",
    ).mockResolvedValue();
    vi.spyOn(
      useConfigurationInventoryStore(),
      "loadClusters",
    ).mockResolvedValue();
    const loadMore = vi.spyOn(store, "loadMore").mockResolvedValue();
    const loadMoreSelection = vi
      .spyOn(store, "loadMoreSelection")
      .mockResolvedValue();
    const wrapper = mount(PoliciesView, { global: { plugins: [PrimeVue] } });

    expect(wrapper.text()).toContain("Nachtlauf");
    expect(wrapper.text()).toContain("Zuweisungen");
    expect(wrapper.text()).toContain("Guest-Overrides");
    expect(wrapper.text()).toContain("vm-101");
    expect(wrapper.text()).not.toMatch(
      /speichern|backup starten|stoppen|abbrechen|jetzt scannen/i,
    );
    await wrapper.get(".policy-pagination button").trigger("click");
    await wrapper.get(".policy-selection-pagination button").trigger("click");
    expect(loadMore).toHaveBeenCalledOnce();
    expect(loadMoreSelection).toHaveBeenCalledOnce();
  });

  it("zeigt Policy- und Auswahlcontrols sowie Blocker nur mit Management-Permission", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = usePoliciesStore();
    store.items = [{ ...policy, canEnable: true, blockers: [] }];
    store.selectedPolicyId = UUID;
    store.selectionItems = [selection("assignment")];
    vi.spyOn(store, "load").mockResolvedValue();
    vi.spyOn(store, "selectPolicyForEditing").mockResolvedValue();
    vi.spyOn(
      useConfiguredBackupTargetsStore(),
      "loadAllForConfiguration",
    ).mockResolvedValue();
    const inventory = useConfigurationInventoryStore();
    vi.spyOn(inventory, "loadClusters").mockResolvedValue();
    vi.spyOn(inventory, "loadHierarchy").mockResolvedValue();
    const commands = useConfigurationCommandsStore();
    const savePolicy = vi.spyOn(commands, "savePolicy").mockResolvedValue(true);
    const setPolicyEnabled = vi
      .spyOn(commands, "setPolicyEnabled")
      .mockResolvedValue(true);
    const changeSelection = vi
      .spyOn(commands, "changeSelection")
      .mockResolvedValue(true);
    commands.error = "Aktivierung blockiert";
    commands.blockers = ["executor_evidence_missing"];
    commands.conflictRevision = 7;
    const wrapper = mount(PoliciesView, { global: { plugins: [PrimeVue] } });

    expect(wrapper.text()).toContain("Policy anlegen");
    expect(wrapper.text()).toContain("Auswahlregel speichern");
    expect(wrapper.text()).toContain("executor_evidence_missing");
    expect(wrapper.text()).toContain("QEMU und LXC");

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Auswahl wird angezeigt"))
      ?.trigger("click");
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Serverstand laden"))
      ?.trigger("click");
    await flushPromises();
    await wrapper.get('form[aria-label="Policies filtern"]').trigger("submit");

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Policy anlegen"))
      ?.trigger("click");
    wrapper.findComponent(PolicyForm).vm.$emit("cancel");
    await wrapper.vm.$nextTick();
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Bearbeiten")
      ?.trigger("click");
    const body = {
      expectedRevision: 1,
      displayName: "Nachtlauf",
      connectionId: UUID,
      clusterId: OTHER_UUID,
      targetId: null,
      priority: null,
      backupMode: null,
      compression: null,
      maximumAgeSeconds: null,
      bytesWrittenThreshold: null,
      cooldownSeconds: null,
      schedule: "collector_cycle",
      legacyMaxfiles: null,
      keepAll: null,
      keepLast: null,
      keepHourly: null,
      keepDaily: null,
      keepWeekly: null,
      keepMonthly: null,
      keepYearly: null,
      retentionExecutionEnabled: false,
      failureNotificationRecipients: [],
    } satisfies PolicyCommandRequest;
    wrapper.findComponent(PolicyForm).vm.$emit("submit", body, UUID);
    await flushPromises();
    expect(savePolicy).toHaveBeenCalledWith(body, UUID);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Aktivieren")
      ?.trigger("click");
    await flushPromises();
    expect(setPolicyEnabled).toHaveBeenCalled();

    wrapper
      .findComponent(PolicySelectionEditor)
      .vm.$emit("change", "selection.upsert", [{ id: UUID }]);
    await flushPromises();
    expect(changeSelection).toHaveBeenCalled();
  });
});
