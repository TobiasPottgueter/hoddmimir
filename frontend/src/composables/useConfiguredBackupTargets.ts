import { computed } from "vue";

import type { ConfiguredBackupTargetBlockerCode } from "@/api/generated/types.gen";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";

const blockerLabels = {
  configuration_incomplete: "Konfiguration ist noch nicht vollständig",
  pbs_binding_missing: "PBS-Zuordnung ist unvollständig",
  executor_evidence_missing: "Aktuelle Executor-Berechtigung fehlt",
} satisfies Record<ConfiguredBackupTargetBlockerCode, string>;

export function configuredBackupTargetBlockerLabel(
  code: ConfiguredBackupTargetBlockerCode,
): string {
  return blockerLabels[code];
}

export function useConfiguredBackupTargets() {
  const store = useConfiguredBackupTargetsStore();
  const hasIncompleteConfiguration = computed(() =>
    store.items.some((target) =>
      target.blockers.includes("configuration_incomplete"),
    ),
  );

  return { store, hasIncompleteConfiguration };
}
