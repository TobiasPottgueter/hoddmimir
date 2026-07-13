import type {
  BackupRun,
  BackupRequestState,
  BackupRunState,
  OperationsWorkerHealth,
} from "@/api/generated/types.gen";

export type BackupNotificationKind =
  "failure" | "attention_required" | "recovery";
export type BackupNotificationState = "pending" | "claimed" | "sent";
export type BackupStopAttemptStatus = Exclude<
  BackupRun["stopAttemptStatus"],
  null | undefined
>;
export type OperationTagSeverity =
  "secondary" | "info" | "success" | "warn" | "danger";

const requestStateLabels: Record<BackupRequestState, string> = {
  pending: "Wartend",
  retry_wait: "Wartet auf Wiederholung",
  leased: "Vom Worker reserviert",
  starting: "Start wird vorbereitet",
  running: "Läuft",
  reconcile_required: "Abgleich erforderlich",
  succeeded: "Erfolgreich",
  failed: "Fehlgeschlagen",
  cancelled: "Abgebrochen",
  unknown: "Unklar",
};

const requestStateSeverities: Record<BackupRequestState, OperationTagSeverity> =
  {
    pending: "secondary",
    retry_wait: "warn",
    leased: "info",
    starting: "info",
    running: "info",
    reconcile_required: "danger",
    succeeded: "success",
    failed: "danger",
    cancelled: "secondary",
    unknown: "danger",
  };

const runStateLabels: Record<BackupRunState, string> = {
  awaiting_submission: "Wartet auf Startübergabe",
  reconcile_required: "Abgleich erforderlich",
  running: "Läuft",
  cancel_requested: "Abbruch angefordert",
  succeeded: "Erfolgreich",
  failed: "Fehlgeschlagen",
  cancelled: "Abgebrochen",
  unknown: "Unklar",
};

const runStateSeverities: Record<BackupRunState, OperationTagSeverity> = {
  awaiting_submission: "info",
  reconcile_required: "danger",
  running: "info",
  cancel_requested: "warn",
  succeeded: "success",
  failed: "danger",
  cancelled: "secondary",
  unknown: "danger",
};

const notificationKindLabels: Record<BackupNotificationKind, string> = {
  failure: "Fehlversuch",
  attention_required: "Eingriff erforderlich",
  recovery: "Entwarnung",
};

const notificationStateLabels: Record<BackupNotificationState, string> = {
  pending: "Ausstehend",
  claimed: "In Zustellung",
  sent: "Zugestellt",
};

const notificationStateSeverities: Record<
  BackupNotificationState,
  OperationTagSeverity
> = {
  pending: "warn",
  claimed: "info",
  sent: "success",
};

const stopAttemptStatusLabels: Record<BackupStopAttemptStatus, string> = {
  dispatching: "Stop-Aufruf wird übergeben",
  requested: "Stop wurde von PVE angenommen",
  ambiguous: "Stop-Antwort ist mehrdeutig",
  definitive_rejection: "Stop wurde definitiv abgelehnt",
  dispatch_unknown: "Stop-Übergabe nach Crash unklar",
};

const workerStatusLabels: Record<OperationsWorkerHealth["status"], string> = {
  starting: "Startet",
  ready: "Bereit",
  busy: "Beschäftigt",
  degraded: "Beeinträchtigt",
  stopping: "Wird beendet",
};
const workerStatusSeverities: Record<
  OperationsWorkerHealth["status"],
  OperationTagSeverity
> = {
  starting: "info",
  ready: "success",
  busy: "info",
  degraded: "warn",
  stopping: "secondary",
};

export const backupRequestStateLabel = (state: BackupRequestState) =>
  requestStateLabels[state];
export const backupRequestStateSeverity = (state: BackupRequestState) =>
  requestStateSeverities[state];
export const backupRunStateLabel = (state: BackupRunState) =>
  runStateLabels[state];
export const backupRunStateSeverity = (state: BackupRunState) =>
  runStateSeverities[state];
export const backupNotificationKindLabel = (kind: BackupNotificationKind) =>
  notificationKindLabels[kind];
export const backupNotificationStateLabel = (state: BackupNotificationState) =>
  notificationStateLabels[state];
export const backupNotificationStateSeverity = (
  state: BackupNotificationState,
) => notificationStateSeverities[state];
export const backupStopAttemptStatusLabel = (status: BackupStopAttemptStatus) =>
  stopAttemptStatusLabels[status];
export const operationsWorkerStatusLabel = (
  status: OperationsWorkerHealth["status"],
) => workerStatusLabels[status];
export const operationsWorkerStatusSeverity = (
  status: OperationsWorkerHealth["status"],
) => workerStatusSeverities[status];
