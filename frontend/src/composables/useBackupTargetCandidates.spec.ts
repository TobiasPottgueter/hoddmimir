import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";

import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useBackupTargetsStore } from "@/stores/backupTargets";
import {
  backupTargetBlockerLabel,
  backupTargetCapacityLabel,
  backupTargetExecutorStatusLabel,
  evidenceFreshnessLabel,
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
  blockers: ["storage_inventory_evidence_stale"],
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
    expect(
      backupTargetExecutorStatusLabel("requires_target_configuration"),
    ).toContain("Zielkonfiguration");
    expect(evidenceFreshnessLabel("future")).toContain("Zukunft");
  });

  it("macht konkrete Freshness-Blocker reaktiv sichtbar", () => {
    const store = useBackupTargetsStore();
    const { hasFreshnessBlocker } = useBackupTargetCandidates();
    expect(hasFreshnessBlocker.value).toBe(false);
    store.items = [candidate];
    expect(hasFreshnessBlocker.value).toBe(true);
    store.items = [
      {
        ...candidate,
        blockers: [],
        executor: {
          ...candidate.executor,
          freshness: "stale",
          blockers: ["executor_evidence_stale"],
        },
      },
    ];
    expect(hasFreshnessBlocker.value).toBe(true);
    store.items = [
      {
        ...candidate,
        blockers: [],
        nodes: [
          {
            nodeId: UUID,
            nodeName: "pve-a",
            configuredForStorage: true,
            enabled: true,
            active: true,
            capacityStatus: "missing",
            totalBytes: null,
            usedBytes: null,
            availableBytes: null,
            observedAt: null,
            blockers: ["capacity_evidence_missing"],
          },
        ],
      },
    ];
    expect(hasFreshnessBlocker.value).toBe(true);
  });
});
