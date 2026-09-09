import { computed } from "vue";

import type { ConfiguredBackupTargetBlockerCode } from "@/api/generated/types.gen";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";

const blockerLabels = {
  minimum_free_unconfigured: "Mindestfreiplatz ist nicht konfiguriert",
  allowed_nodes_empty: "Es ist kein Node erlaubt",
  concurrency_unconfigured: "Parallelitätslimit ist nicht konfiguriert",
  candidate_evidence_missing: "Zielkandidaten-Evidenz fehlt",
  candidate_rejected: "Zielkandidat ist aktuell nicht nutzbar",
  candidate_evidence_stale: "Zielkandidaten-Evidenz ist veraltet",
  candidate_evidence_future: "Zielkandidaten-Evidenz liegt in der Zukunft",
  inventory_evidence_missing: "Inventar-Evidenz fehlt",
  inventory_evidence_stale: "Inventar-Evidenz ist veraltet",
  inventory_evidence_future: "Inventar-Evidenz liegt in der Zukunft",
  capacity_evidence_missing: "Kapazitäts-Evidenz fehlt",
  capacity_evidence_stale: "Kapazitäts-Evidenz ist veraltet",
  capacity_evidence_future: "Kapazitäts-Evidenz liegt in der Zukunft",
  executor_evidence_missing: "Aktuelle Executor-Berechtigung fehlt",
  executor_evidence_stale: "Executor-Berechtigung ist veraltet",
  executor_evidence_future: "Executor-Berechtigung liegt in der Zukunft",
  executor_unauthorized: "Executor ist nicht berechtigt",
  pbs_mapping_required: "PBS-Zuordnung ist erforderlich",
  pbs_mapping_unexpected: "PBS-Zuordnung ist für dieses Ziel unzulässig",
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
      target.blockers.some((blocker) =>
        [
          "minimum_free_unconfigured",
          "allowed_nodes_empty",
          "concurrency_unconfigured",
          "pbs_mapping_required",
          "pbs_mapping_unexpected",
        ].includes(blocker),
      ),
    ),
  );

  return { store, hasIncompleteConfiguration };
}
