import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import Select from "primevue/select";
import { describe, expect, it } from "vitest";

import type {
  ConfiguredBackupTarget,
  ConfiguredPolicy,
  PveClusterResource,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import PolicyForm from "./PolicyForm.vue";

const policy = {
  id: UUID,
  revision: 3,
  status: "draft",
  displayName: "Nacht",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  targetId: UUID,
  targetName: "Ziel",
  priority: 300,
  mode: "snapshot",
  compression: "zstd",
  maximumAgeSeconds: 3600,
  bytesWrittenThreshold: "1000",
  cooldownSeconds: 60,
  schedule: "collector_cycle",
  desiredRetention: {
    legacyMaxFiles: null,
    keepAll: false,
    keepLast: 2,
    keepHourly: null,
    keepDaily: null,
    keepWeekly: null,
    keepMonthly: null,
    keepYearly: null,
  },
  retentionExecutionEnabled: false,
  failureNotificationRecipients: ["ops@example.test"],
  disabledAt: null,
  canEnable: false,
  blockers: [],
} satisfies ConfiguredPolicy;
const cluster = {
  id: OTHER_UUID,
  connectionId: UUID,
  connectionName: "PVE",
  parentId: null,
  displayName: "cluster-a",
  inventoryState: "active",
  firstSeenAt: "2026-07-12T10:00:00.000000Z",
  lastSeenAt: "2026-07-12T10:00:00.000000Z",
  archivedAt: null,
  stateObservedAt: null,
  kind: "pve_cluster",
  attributes: { topology: "clustered" },
} satisfies PveClusterResource;
const target = {
  id: UUID,
  revision: 1,
  enabled: true,
  displayName: "Ziel",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageId: UUID,
  storageName: "backup",
  storageType: "dir",
  minimumFreeBytes: null,
  fixedParallelLimit: null,
  pbsConnectionId: null,
  pbsDatastoreId: null,
  pbsNamespaceId: null,
  disabledAt: null,
  allowedNodes: [],
  canEnable: true,
  blockers: [],
} satisfies ConfiguredBackupTarget;

describe("PolicyForm", () => {
  it("projiziert den Serverstand vollständig in einen Policy-Command", async () => {
    const wrapper = mount(PolicyForm, {
      props: { policy, targets: [target], clusters: [cluster], pending: false },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get("form").trigger("submit");
    expect(wrapper.emitted("submit")?.[0]?.[0]).toMatchObject({
      expectedRevision: 3,
      displayName: "Nacht",
      backupMode: "snapshot",
      compression: "zstd",
      maximumAgeSeconds: "3600",
      bytesWrittenThreshold: "1000",
      cooldownSeconds: "60",
      keepLast: 2,
      schedule: "collector_cycle",
      failureNotificationRecipients: ["ops@example.test"],
    });
    expect(wrapper.emitted("submit")?.[0]?.[1]).toBe(UUID);
    for (const input of wrapper.findAll("input")) {
      if (input.attributes("type") === "checkbox") {
        await input.setValue(!input.element.checked);
      } else {
        await input.setValue(input.element.value);
      }
    }
    for (const select of wrapper.findAllComponents(Select)) {
      select.vm.$emit("update:modelValue", null);
    }
  });

  it("blockiert einen unvollständigen Create-Draft im Client", async () => {
    const wrapper = mount(PolicyForm, {
      props: { policy: null, targets: [], clusters: [], pending: false },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get("form").trigger("submit");
    expect(wrapper.text()).toContain("sind erforderlich");
    expect(wrapper.emitted("submit")).toBeUndefined();
    await wrapper.get('button[type="button"]').trigger("click");
    expect(wrapper.emitted("cancel")).toHaveLength(1);
  });

  it("sendet Create mit Revision 0 und Inventar-Cluster statt freier UUID", async () => {
    const wrapper = mount(PolicyForm, {
      props: { policy: null, targets: [], clusters: [cluster], pending: false },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get('input[type="text"]').setValue("Neue Policy");
    wrapper
      .findAllComponents(Select)[0]
      ?.vm.$emit("update:modelValue", OTHER_UUID);
    await wrapper.vm.$nextTick();
    await wrapper.get("form").trigger("submit");

    expect(wrapper.emitted("submit")?.[0]?.[0]).toMatchObject({
      expectedRevision: 0,
      connectionId: UUID,
      clusterId: OTHER_UUID,
      failureNotificationRecipients: [],
    });
  });

  it("normalisiert Empfänger und blockiert case-insensitive Duplikate", async () => {
    const wrapper = mount(PolicyForm, {
      props: { policy: null, targets: [], clusters: [cluster], pending: false },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get('input[type="text"]').setValue("Mail Policy");
    wrapper
      .findAllComponents(Select)[0]
      ?.vm.$emit("update:modelValue", OTHER_UUID);
    await wrapper
      .get("textarea")
      .setValue("alerts@example.test, ALERTS@example.test");
    await wrapper.get("form").trigger("submit");
    expect(wrapper.text()).toContain("eindeutige E-Mail-Empfänger");
    expect(wrapper.emitted("submit")).toBeUndefined();
  });
});
