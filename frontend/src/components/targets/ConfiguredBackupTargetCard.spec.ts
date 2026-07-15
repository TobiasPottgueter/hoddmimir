import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";

import type { ConfiguredBackupTarget } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import ConfiguredBackupTargetCard from "./ConfiguredBackupTargetCard.vue";

const target = {
  id: UUID,
  revision: 7,
  enabled: false,
  displayName: "Primärziel",
  connectionId: UUID,
  connectionName: "PVE Produktion",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageId: UUID,
  storageName: "pbs-primary",
  storageType: "pbs",
  minimumFreeBytes: "18446744073709551615",
  fixedParallelLimit: 2,
  pbsConnectionId: UUID,
  pbsDatastoreId: OTHER_UUID,
  pbsNamespaceId: null,
  disabledAt: "2026-07-12T10:00:00.000000Z",
  allowedNodes: [{ id: OTHER_UUID, name: "pve-a" }],
  canEnable: false,
  blockers: ["minimum_free_unconfigured"],
} satisfies ConfiguredBackupTarget;

describe("ConfiguredBackupTargetCard", () => {
  it("zeigt Ziel-, Node- und Blocker-Evidenz vollständig read-only", () => {
    const wrapper = mount(ConfiguredBackupTargetCard, {
      props: { target },
      global: { plugins: [PrimeVue] },
    });

    expect(wrapper.text()).toContain("Primärziel");
    expect(wrapper.text()).toContain("PVE Produktion");
    expect(wrapper.text()).toContain("pve-a");
    expect(wrapper.text()).toContain("18.446.744.073.709.551.615 B");
    expect(wrapper.text()).toContain("Mindestfreiplatz ist nicht konfiguriert");
    expect(wrapper.text()).toContain("Nicht aktivierbar");
    expect(wrapper.findAll("button")).toHaveLength(0);
    expect(wrapper.text()).not.toMatch(
      /speichern|starten|stoppen|abbrechen|scannen/i,
    );
  });

  it("emittiert Management-Aktionen und lässt ein serverseitig freigegebenes Ziel aktivieren", async () => {
    const manageable = { ...target, canEnable: true, blockers: [] };
    const wrapper = mount(ConfiguredBackupTargetCard, {
      props: { target: manageable, canManage: true },
      global: { plugins: [PrimeVue] },
    });
    const buttons = wrapper.findAll("button");
    await buttons[0]?.trigger("click");
    await buttons[1]?.trigger("click");
    expect(wrapper.emitted("edit")?.[0]).toEqual([manageable]);
    expect(wrapper.emitted("toggle")?.[0]).toEqual([manageable]);
  });
});
