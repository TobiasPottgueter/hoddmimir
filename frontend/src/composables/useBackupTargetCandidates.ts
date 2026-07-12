import { computed } from "vue";

import type {
  BackupTargetBlockerCode,
  BackupTargetCapacityStatus,
  PbsEndpointMatchStatus,
} from "@/api/generated/types.gen";
import { useBackupTargetsStore } from "@/stores/backupTargets";

const blockerLabels = {
  freshness_policy_unconfigured:
    "Freshness-Regel ist fachlich noch nicht festgelegt",
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
  node_offline: "Node ist nicht online",
  node_storage_disabled: "Storage ist auf diesem Node deaktiviert",
  node_storage_inactive: "Storage ist auf diesem Node nicht aktiv",
  capacity_unavailable: "Kapazität ist nicht verfügbar",
  capacity_invalid: "Kapazitätsmessung ist ungültig",
  pbs_mapping_missing: "PVE-Storage besitzt keine PBS-Zuordnung",
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

export function formatDecimalBytes(value: string | null): string {
  if (value === null) return "Keine Messung";
  return `${value.replace(/\B(?=(\d{3})+(?!\d))/g, ".")} B`;
}

export function useBackupTargetCandidates() {
  const store = useBackupTargetsStore();
  const freshnessUnresolved = computed(() =>
    store.items.some((candidate) =>
      candidate.blockers.includes("freshness_policy_unconfigured"),
    ),
  );

  return { store, freshnessUnresolved };
}
