import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";

import type {
  BackupTargetCandidate,
  ConfiguredBackupTarget,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import BackupTargetForm from "./BackupTargetForm.vue";

const target = {
  id: UUID,
  revision: 4,
  enabled: false,
  displayName: "Primär",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageId: UUID,
  storageName: "pbs",
  storageType: "pbs",
  minimumFreeBytes: "1000",
  fixedParallelLimit: 2,
  pbsConnectionId: UUID,
  pbsDatastoreId: OTHER_UUID,
  pbsNamespaceId: null,
  disabledAt: null,
  allowedNodes: [{ id: OTHER_UUID, name: "pve-a" }],
  canEnable: true,
  blockers: [],
} satisfies ConfiguredBackupTarget;

describe("BackupTargetForm", () => {
  it("übernimmt und löscht Zielvorgaben explizit", async () => {
    const wrapper = mount(BackupTargetForm, {
      props: {
        target: {
          ...target,
          defaultBackupMode: "stop",
          defaultCompression: "gzip",
          defaultKeepLast: 7,
        },
        candidate: null,
        candidates: [],
        pending: false,
      },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get("form").trigger("submit");
    expect(wrapper.emitted("submit")?.[0]?.[0]).toMatchObject({
      defaultBackupMode: "stop",
      defaultCompression: "gzip",
      defaultKeepLast: 7,
      defaultKeepAll: null,
    });
    const selects = wrapper.findAllComponents({ name: "Select" });
    selects[1]!.vm.$emit("update:modelValue", null);
    selects[2]!.vm.$emit("update:modelValue", null);
    await wrapper.get("form").trigger("submit");
    expect(wrapper.emitted("submit")?.[1]?.[0]).toMatchObject({
      defaultBackupMode: null,
      defaultCompression: null,
      defaultKeepLast: 7,
    });
  });

  it("erzeugt einen vollständigen revisionierten Update-Request", async () => {
    const wrapper = mount(BackupTargetForm, {
      props: { target, candidate: null, candidates: [], pending: false },
      global: { plugins: [PrimeVue] },
    });

    await wrapper.get("form").trigger("submit");
    expect(wrapper.emitted("submit")?.[0]?.[0]).toMatchObject({
      expectedRevision: 4,
      displayName: "Primär",
      minimumFreeBytes: "1000",
      allowedNodeIds: [OTHER_UUID],
    });
    expect(wrapper.emitted("submit")?.[0]?.[1]).toBe(UUID);
    for (const input of wrapper.findAll("input")) {
      if (input.attributes("type") !== "checkbox")
        await input.setValue(input.element.value);
    }
    await wrapper.get('button[type="button"]').trigger("click");
    expect(wrapper.emitted("cancel")).toHaveLength(1);
  });

  it("übernimmt Kandidatenevidenz und validiert leere Pflichtfelder", async () => {
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
      observedAt: null,
      canEnable: true,
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
      blockers: [],
    } satisfies BackupTargetCandidate;
    const wrapper = mount(BackupTargetForm, {
      props: {
        target: null,
        candidate,
        candidates: [candidate],
        pending: false,
      },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get("form").trigger("submit");
    expect(wrapper.emitted("submit")?.[0]?.[0]).toMatchObject({
      expectedRevision: 0,
      displayName: "backup",
      storageId: UUID,
    });

    await wrapper.setProps({ candidate: null, candidates: [] });
    await wrapper.get("form").trigger("submit");
    expect(wrapper.text()).toContain("sind erforderlich");
    expect(wrapper.emitted("submit")).toHaveLength(1);
  });
});
