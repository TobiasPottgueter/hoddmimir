import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";

import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useBackupTargetsStore } from "@/stores/backupTargets";
import {
  backupTargetBlockerLabel,
  backupTargetCapacityLabel,
  formatDecimalBytes,
  pbsEndpointMatchLabel,
  useBackupTargetCandidates,
} from "./useBackupTargetCandidates";

const candidate = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster",
  storageName: "backup",
  storageType: "dir",
  shared: false,
  inventoryState: "active",
  observedAt: "2026-07-12T10:00:00.000000Z",
  canEnable: false,
  nodes: [],
  pbs: null,
  blockers: ["freshness_policy_unconfigured"],
} satisfies BackupTargetCandidate;

describe("useBackupTargetCandidates", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("formatiert UInt64-Bytes ohne JavaScript-Rundung", () => {
    expect(formatDecimalBytes("18446744073709551615")).toBe(
      "18.446.744.073.709.551.615 B",
    );
    expect(formatDecimalBytes("0")).toBe("0 B");
    expect(formatDecimalBytes(null)).toBe("Keine Messung");
  });

  it("liefert geschlossene deutsche Evidence-Labels", () => {
    expect(backupTargetBlockerLabel("node_offline")).toContain("nicht online");
    expect(backupTargetCapacityLabel("measured")).toBe("Gemessen");
    expect(pbsEndpointMatchLabel("ambiguous")).toBe("Mehrdeutig");
  });

  it("macht die ungelöste Freshness-Grenze reaktiv sichtbar", () => {
    const store = useBackupTargetsStore();
    const { freshnessUnresolved } = useBackupTargetCandidates();
    expect(freshnessUnresolved.value).toBe(false);
    store.items = [candidate];
    expect(freshnessUnresolved.value).toBe(true);
  });
});
