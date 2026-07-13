import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";

import type { ConfiguredBackupTarget } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";
import {
  configuredBackupTargetBlockerLabel,
  useConfiguredBackupTargets,
} from "./useConfiguredBackupTargets";

const target = {
  id: UUID,
  revision: 1,
  enabled: false,
  displayName: "Primärziel",
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
  canEnable: false,
  blockers: ["configuration_incomplete"],
} satisfies ConfiguredBackupTarget;

describe("useConfiguredBackupTargets", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("liefert geschlossene deutsche Konfigurations-Labels", () => {
    expect(
      configuredBackupTargetBlockerLabel("configuration_incomplete"),
    ).toContain("nicht vollständig");
    expect(configuredBackupTargetBlockerLabel("pbs_binding_missing")).toContain(
      "PBS-Zuordnung",
    );
    expect(
      configuredBackupTargetBlockerLabel("executor_evidence_missing"),
    ).toContain("Executor");
  });

  it("macht unvollständige Konfiguration reaktiv sichtbar", () => {
    const store = useConfiguredBackupTargetsStore();
    const { hasIncompleteConfiguration } = useConfiguredBackupTargets();
    expect(hasIncompleteConfiguration.value).toBe(false);
    store.items = [target];
    expect(hasIncompleteConfiguration.value).toBe(true);
  });
});
