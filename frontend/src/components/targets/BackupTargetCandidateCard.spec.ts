import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";

import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import BackupTargetCandidateCard from "./BackupTargetCandidateCard.vue";

const candidate = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE Produktion",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageName: "pbs-primary",
  storageType: "pbs",
  shared: true,
  inventoryState: "active",
  observedAt: "2026-07-12T10:00:00.000000Z",
  canEnable: false,
  blockers: ["freshness_policy_unconfigured"],
  nodes: [
    {
      nodeId: OTHER_UUID,
      nodeName: "pve-a",
      configuredForStorage: true,
      enabled: true,
      active: true,
      capacityStatus: "measured",
      totalBytes: "18446744073709551615",
      usedBytes: "1",
      availableBytes: "18446744073709551614",
      observedAt: "2026-07-12T10:00:00.000000Z",
      blockers: [],
    },
  ],
  pbs: {
    server: "pbs.example.test",
    port: 8007,
    datastore: "primary",
    namespace: null,
    mappingObservedAt: "2026-07-12T10:00:00.000000Z",
    endpointMatch: "matched",
    pbsConnectionId: UUID,
    pbsServerId: UUID,
    pbsDatastoreId: UUID,
    pbsNamespaceId: UUID,
    capacitySemantics: "datastore_filesystem",
    totalBytes: "10000",
    usedBytes: "1000",
    availableBytes: "9000",
    capacityObservedAt: "2026-07-12T10:00:00.000000Z",
    blockers: [],
  },
} satisfies BackupTargetCandidate;

describe("BackupTargetCandidateCard", () => {
  it("zeigt PVE/PBS-Evidenz und UInt64-Werte vollständig read-only", () => {
    const wrapper = mount(BackupTargetCandidateCard, {
      props: { candidate },
      global: { plugins: [PrimeVue] },
    });

    expect(wrapper.text()).toContain("Nicht aktivierbar");
    expect(wrapper.text()).toContain("Freshness-Regel");
    expect(wrapper.text()).toContain("pve-a");
    expect(wrapper.text()).toContain("18.446.744.073.709.551.615 B");
    expect(wrapper.text()).toContain("pbs.example.test:8007");
    expect(wrapper.text()).toContain("primary / @root");
    expect(wrapper.findAll("button")).toHaveLength(0);
    expect(wrapper.text()).not.toMatch(
      /speichern|starten|stoppen|abbrechen|scannen/i,
    );
  });
});
