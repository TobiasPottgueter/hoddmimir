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
  pve_evidence_missing: "PVE-Evidenz fehlt",
  pve_evidence_stale: "PVE-Evidenz ist veraltet",
  pve_evidence_future: "PVE-Evidenz liegt in der Zukunft",
  unsupported_pve_major: "PVE-Version wird nicht unterstützt",
  target_evidence_missing: "Ziel-Evidenz fehlt",
  target_evidence_stale: "Ziel-Evidenz ist veraltet",
  target_evidence_future: "Ziel-Evidenz liegt in der Zukunft",
  target_disabled: "Backupziel ist deaktiviert oder nicht nutzbar",
  executor_evidence_missing: "Executor-Evidenz fehlt",
  executor_evidence_stale: "Executor-Evidenz ist veraltet",
  executor_evidence_future: "Executor-Evidenz liegt in der Zukunft",
  executor_unauthorized: "Executor ist nicht berechtigt",
  retention_execution_forbidden_for_pbs_target:
    "Retention-Ausführung ist für PBS-Ziele nicht zulässig",
  target_unconfigured: "Backupziel ist nicht konfiguriert",
  mode_unconfigured: "Backupmodus ist nicht konfiguriert",
  compression_unconfigured: "Kompression ist nicht konfiguriert",
  retention_unconfigured: "Retention ist nicht konfiguriert",
  priority_unconfigured: "Priorität ist nicht konfiguriert",
  thresholds_unconfigured: "Schwellwerte sind nicht konfiguriert",
  schedule_unconfigured: "Zeitplan ist nicht konfiguriert",
  retention_incompatible: "Retention ist mit der PVE-Version nicht kompatibel",
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
