import { computed } from "vue";

import type {
  PolicyBlockerCode,
  PolicyRetention,
  PolicySelectionEntry,
  PolicyStatus,
} from "@/api/generated/types.gen";
import { usePoliciesStore } from "@/stores/policies";

const statusLabels = {
  draft: "Entwurf",
  enabled: "Aktiviert",
  disabled: "Deaktiviert",
} satisfies Record<PolicyStatus, string>;

const blockerLabels = {
  configuration_incomplete: "Konfiguration ist unvollständig",
  executor_evidence_missing: "Executor-Evidenz fehlt",
  retention_execution_forbidden_for_pbs_target:
    "Retention-Ausführung ist für PBS-Ziele nicht zulässig",
} satisfies Record<PolicyBlockerCode, string>;

const scopeLabels = {
  global: "Global",
  connection: "Verbindung",
  cluster: "Cluster",
  node: "Node",
  guest: "Gast",
} satisfies Record<PolicySelectionEntry["scope"], string>;

export const policyStatusLabel = (status: PolicyStatus) => statusLabels[status];
export const policyBlockerLabel = (blocker: PolicyBlockerCode) =>
  blockerLabels[blocker];
export const policyScopeLabel = (scope: PolicySelectionEntry["scope"]) =>
  scopeLabels[scope];

export function retentionSummary(retention: PolicyRetention | null): string {
  if (retention === null) return "Nicht festgelegt";
  const values: Array<readonly [string, number | null]> = [
    ["Legacy", retention.legacyMaxFiles],
    ["Letzte", retention.keepLast],
    ["Stündlich", retention.keepHourly],
    ["Täglich", retention.keepDaily],
    ["Wöchentlich", retention.keepWeekly],
    ["Monatlich", retention.keepMonthly],
    ["Jährlich", retention.keepYearly],
  ];
  const configured = values
    .filter((entry): entry is readonly [string, number] => entry[1] !== null)
    .map(([label, value]) => `${label}: ${value}`);
  if (retention.keepAll === true) configured.unshift("Alle behalten");
  return configured.length === 0
    ? "Keine Werte gesetzt"
    : configured.join(" · ");
}

export function usePolicies() {
  const store = usePoliciesStore();
  const assignments = computed(() =>
    store.selectionItems.filter((item) => item.kind === "assignment"),
  );
  const guestOverrides = computed(() =>
    store.selectionItems.filter((item) => item.kind === "guest_override"),
  );
  return { store, assignments, guestOverrides };
}
