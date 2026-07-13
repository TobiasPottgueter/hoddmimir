import { computed } from "vue";
import { useShadowStore } from "@/stores/shadow";
import type {
  ShadowGateCode,
  ShadowGateDetailCode,
  ShadowGateScope,
  ShadowOutcome,
  ShadowReason,
} from "@/api/shadowApi";

const gateLabels: Record<ShadowGateCode, string> = {
  connection_enabled: "Verbindung aktiviert",
  cluster_enabled: "Cluster aktiviert",
  node_enabled: "Node aktiviert",
  guest_enabled: "Gast aktiviert",
  policy_enabled: "Policy aktiviert",
  target_enabled: "Backup-Ziel aktiviert",
  explicit_exclusion_absent: "Keine explizite Ausnahme",
  guest_active: "Gast aktiv",
  inventory_fresh: "Inventar aktuell",
  placement_present: "Platzierung vorhanden",
  placement_fresh: "Platzierung aktuell",
  active_request_absent: "Keine aktive Anforderung",
  target_node_allowed: "Node am Ziel erlaubt",
  target_storage_enabled: "Zielspeicher aktiviert",
  target_storage_active: "Zielspeicher aktiv",
  executor_authorization_fresh: "Executor-Autorisierung aktuell",
  executor_authorized: "Executor autorisiert",
  capacity_fresh: "Kapazität aktuell",
  minimum_free_space: "Mindestfreiraum",
  node_concurrency: "Node-Parallelität verfügbar",
  target_concurrency: "Ziel-Parallelität verfügbar",
  pbs_mapping_valid: "PBS-Zuordnung gültig",
};

const outcomeLabels: Record<ShadowOutcome, string> = {
  eligible: "Geeignet",
  blocked: "Blockiert",
  not_due: "Nicht fällig",
  deduplicated: "Dedupliziert",
};
const reasonLabels: Record<ShadowReason, string> = {
  manual: "Manuell",
  never_backed_up: "Noch nie gesichert",
  max_age: "Maximalalter",
  bytes_written: "Geschriebene Bytes",
};
const scopeLabels: Record<ShadowGateScope, string> = {
  connection: "Verbindung",
  cluster: "Cluster",
  node: "Node",
  guest: "Gast",
  policy: "Policy",
  target: "Ziel",
  inventory: "Inventar",
  placement: "Platzierung",
  authorization: "Autorisierung",
  capacity: "Kapazität",
  concurrency: "Parallelität",
  request: "Anforderung",
  pbs_mapping: "PBS-Zuordnung",
};
const detailLabels: Record<ShadowGateDetailCode, string> = {
  passed: "Bestanden",
  disabled: "Deaktiviert",
  explicitly_excluded: "Explizit ausgeschlossen",
  archived: "Archiviert",
  missing: "Fehlt",
  stale: "Veraltet",
  not_allowed: "Nicht erlaubt",
  inactive: "Inaktiv",
  unauthorized: "Nicht autorisiert",
  insufficient_free_space: "Zu wenig freier Speicher",
  concurrency_limit_reached: "Parallelitätsgrenze erreicht",
  invalid_mapping: "Ungültige Zuordnung",
  active_request_exists: "Aktive Anforderung vorhanden",
};

export const shadowGateLabel = (code: ShadowGateCode): string =>
  gateLabels[code];
export const shadowOutcomeLabel = (outcome: ShadowOutcome): string =>
  outcomeLabels[outcome];
export const shadowReasonLabel = (reason: ShadowReason | null): string =>
  reason === null ? "Kein Sicherungsgrund" : reasonLabels[reason];
export const shadowScopeLabel = (scope: ShadowGateScope): string =>
  scopeLabels[scope];
export const shadowDetailLabel = (detail: ShadowGateDetailCode): string =>
  detailLabels[detail];

export function useShadow() {
  const store = useShadowStore();
  const passedGates = computed(
    () => store.detail?.gates.filter((gate) => gate.passed) ?? [],
  );
  const blockedGates = computed(
    () => store.detail?.gates.filter((gate) => !gate.passed) ?? [],
  );
  return { store, passedGates, blockedGates };
}
