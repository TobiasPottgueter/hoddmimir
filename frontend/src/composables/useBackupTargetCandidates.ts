import { computed } from "vue";

import type {
  BackupTargetBlockerCode,
  BackupTargetCapacityStatus,
  BackupTargetExecutorStatus,
  PbsEndpointMatchStatus,
} from "@/api/generated/types.gen";
import { useBackupTargetsStore } from "@/stores/backupTargets";

const blockerLabels = {
  storage_inventory_evidence_missing: "Storage-Inventarevidenz fehlt",
  storage_inventory_evidence_stale:
    "Storage-Inventarevidenz ist älter als fünf Minuten",
  storage_inventory_evidence_future:
    "Storage-Inventarevidenz liegt in der Zukunft",
  executor_evidence_missing: "Executor-Evidenz fehlt",
  executor_evidence_partial: "Executor-Evidenz ist unvollständig",
  executor_evidence_stale: "Executor-Evidenz ist älter als fünf Minuten",
  executor_evidence_future: "Executor-Evidenz liegt in der Zukunft",
  executor_unauthorized: "Executor besitzt nicht alle erforderlichen Rechte",
  connection_disabled: "PVE-Verbindung ist deaktiviert",
  connection_not_pve: "Verbindung ist keine PVE-Verbindung",
  cluster_archived: "Cluster ist archiviert",
  storage_archived: "Storage ist archiviert",
  storage_disabled: "Storage ist in PVE deaktiviert",
  backup_content_unsupported: "Storage unterstützt keinen Backup-Content",
  no_active_node: "Kein aktiver Cluster-Node vorhanden",
  no_usable_node: "Kein Node erfüllt alle Storage-Gates",
  storage_not_configured_on_node:
    "Storage ist für diesen Node nicht konfiguriert",
  node_state_missing: "Node-Storage-Evidenz fehlt",
  node_state_evidence_missing: "Node-Zustandsevidenz fehlt",
  node_state_evidence_stale: "Node-Zustandsevidenz ist älter als fünf Minuten",
  node_state_evidence_future: "Node-Zustandsevidenz liegt in der Zukunft",
  node_offline: "Node ist nicht online",
  node_storage_disabled: "Storage ist auf diesem Node deaktiviert",
  node_storage_inactive: "Storage ist auf diesem Node nicht aktiv",
  capacity_unavailable: "Kapazität ist nicht verfügbar",
  capacity_invalid: "Kapazitätsmessung ist ungültig",
  capacity_evidence_missing: "Node-Kapazitätsevidenz fehlt",
  capacity_evidence_stale: "Node-Kapazitätsevidenz ist älter als fünf Minuten",
  capacity_evidence_future: "Node-Kapazitätsevidenz liegt in der Zukunft",
  pbs_mapping_missing: "PVE-Storage besitzt keine PBS-Zuordnung",
  pbs_mapping_evidence_missing: "PBS-Zuordnungsevidenz fehlt",
  pbs_mapping_evidence_stale:
    "PBS-Zuordnungsevidenz ist älter als fünf Minuten",
  pbs_mapping_evidence_future: "PBS-Zuordnungsevidenz liegt in der Zukunft",
  pbs_endpoint_unresolved:
    "PBS-Endpunkt konnte nicht eindeutig zugeordnet werden",
  pbs_endpoint_ambiguous: "Mehrere PBS-Verbindungen passen zum Endpunkt",
  pbs_connection_disabled: "PBS-Verbindung ist deaktiviert",
  pbs_server_missing: "PBS-Serverinventar fehlt",
  pbs_datastore_missing: "PBS-Datastore wurde nicht gefunden",
  pbs_datastore_archived: "PBS-Datastore ist archiviert",
  pbs_datastore_read_only: "PBS-Datastore erlaubt keine Backup-Schreibzugriffe",
  pbs_namespace_missing: "PBS-Namespace wurde nicht gefunden",
  pbs_namespace_archived: "PBS-Namespace ist archiviert",
  pbs_capacity_missing: "PBS-Kapazitätsevidenz fehlt",
  pbs_capacity_evidence_missing: "PBS-Kapazitätszeitpunkt fehlt",
  pbs_capacity_evidence_stale:
    "PBS-Kapazitätsevidenz ist älter als fünf Minuten",
  pbs_capacity_evidence_future: "PBS-Kapazitätsevidenz liegt in der Zukunft",
  pbs_remote_capacity_unproven:
    "S3-Local-Cache beweist keine freie Remote-Kapazität",
} satisfies Record<BackupTargetBlockerCode, string>;

const capacityLabels = {
  missing: "Fehlt",
  measured: "Gemessen",
  unavailable: "Nicht verfügbar",
  invalid: "Ungültig",
} satisfies Record<BackupTargetCapacityStatus, string>;

const endpointLabels = {
  matched: "Host/Port-Evidenz gefunden",
  unresolved: "Nicht zugeordnet",
  ambiguous: "Mehrdeutig",
} satisfies Record<PbsEndpointMatchStatus, string>;

const executorStatusLabels = {
  requires_target_configuration: "Zielkonfiguration erforderlich",
  missing: "Executor-Evidenz fehlt",
  partial: "Executor-Evidenz unvollständig",
  authorized: "Executor autorisiert",
  unauthorized: "Executor nicht autorisiert",
} satisfies Record<BackupTargetExecutorStatus, string>;

const freshnessLabels = {
  missing: "Keine Evidenz",
  fresh: "Aktuell",
  stale: "Veraltet",
  future: "Liegt in der Zukunft",
} as const;

export function backupTargetBlockerLabel(
  code: BackupTargetBlockerCode,
): string {
  return blockerLabels[code];
}

export function backupTargetCapacityLabel(
  status: BackupTargetCapacityStatus,
): string {
  return capacityLabels[status];
}

export function pbsEndpointMatchLabel(status: PbsEndpointMatchStatus): string {
  return endpointLabels[status];
}

export function backupTargetExecutorStatusLabel(
  status: BackupTargetExecutorStatus,
): string {
  return executorStatusLabels[status];
}

export function evidenceFreshnessLabel(
  freshness: keyof typeof freshnessLabels,
): string {
  return freshnessLabels[freshness];
}

export function formatDecimalBytes(value: string | null): string {
  if (value === null) return "Keine Messung";
  return `${value.replace(/\B(?=(\d{3})+(?!\d))/g, ".")} B`;
}

export function useBackupTargetCandidates() {
  const store = useBackupTargetsStore();
  const hasFreshnessBlocker = computed(() =>
    store.items.some((candidate) => {
      const blockers = [
        ...candidate.blockers,
        ...candidate.nodes.flatMap((node) => node.blockers),
        ...candidate.executor.blockers,
        ...(candidate.pbs?.blockers ?? []),
      ];
      return blockers.some((blocker) =>
        /_evidence_(?:missing|stale|future)$/.test(blocker),
      );
    }),
  );

  return { store, hasFreshnessBlocker };
}
