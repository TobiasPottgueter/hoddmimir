import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";

import type { ConfiguredPolicy } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import ConfiguredPolicyCard from "./ConfiguredPolicyCard.vue";

const policy = {
  id: UUID,
  revision: 2,
  status: "draft",
  displayName: "Nachtlauf",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  targetId: null,
  targetName: null,
  priority: 10,
  mode: "snapshot",
  compression: "zstd",
  maximumAgeSeconds: 3600,
  bytesWrittenThreshold: "18446744073709551615",
  cooldownSeconds: 120,
  schedule: "02:00",
  desiredRetention: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: ["ops@example.test"],
  disabledAt: null,
  canEnable: false,
  blockers: ["target_unconfigured", "executor_evidence_missing"],
} satisfies ConfiguredPolicy;

describe("ConfiguredPolicyCard", () => {
  it("zeigt den vollständigen Read-only-Vertrag und öffnet nur die Auswahl", async () => {
    const wrapper = mount(ConfiguredPolicyCard, {
      props: { policy, selected: false },
      global: { plugins: [PrimeVue] },
    });
    expect(wrapper.text()).toContain("Nachtlauf");
    expect(wrapper.text()).toContain("18.446.744.073.709.551.615 B");
    expect(wrapper.text()).toContain("Executor-Evidenz");
    expect(wrapper.text()).toContain("ops@example.test");
    expect(wrapper.text()).not.toMatch(/speichern|aktivieren|löschen/i);
    await wrapper.get("button").trigger("click");
    expect(wrapper.emitted("showSelection")?.[0]).toEqual([UUID]);
  });

  it("emittiert revisionierte Management-Aktionen für eine blockerfreie Policy", async () => {
    const manageable = { ...policy, canEnable: true, blockers: [] };
    const wrapper = mount(ConfiguredPolicyCard, {
      props: { policy: manageable, selected: true, canManage: true },
      global: { plugins: [PrimeVue] },
    });
    const buttons = wrapper.findAll("button");
    await buttons[1]?.trigger("click");
    await buttons[2]?.trigger("click");
    expect(wrapper.emitted("edit")?.[0]).toEqual([manageable]);
    expect(wrapper.emitted("toggle")?.[0]).toEqual([manageable]);
  });
});
