import { describe, expect, it } from "vitest";

import {
  backupNotificationKindLabel,
  backupNotificationStateLabel,
  backupNotificationStateSeverity,
  backupRequestStateLabel,
  backupRequestStateSeverity,
  backupRunStateLabel,
  backupRunStateSeverity,
  backupStopAttemptStatusLabel,
  operationsWorkerStatusLabel,
  operationsWorkerStatusSeverity,
} from "./useBackupOperations";

describe("backup operations labels", () => {
  it("maps every closed request state", () => {
    expect(
      [
        "pending",
        "retry_wait",
        "leased",
        "starting",
        "running",
        "reconcile_required",
        "succeeded",
        "failed",
        "cancelled",
        "unknown",
      ].map((state) => [
        backupRequestStateLabel(state as never),
        backupRequestStateSeverity(state as never),
      ]),
    ).toEqual([
      ["Wartend", "secondary"],
      ["Wartet auf Wiederholung", "warn"],
      ["Vom Worker reserviert", "info"],
      ["Start wird vorbereitet", "info"],
      ["Läuft", "info"],
      ["Abgleich erforderlich", "danger"],
      ["Erfolgreich", "success"],
      ["Fehlgeschlagen", "danger"],
      ["Abgebrochen", "secondary"],
      ["Unklar", "danger"],
    ]);
  });

  it("maps every closed run and notification state", () => {
    expect(
      [
        "awaiting_submission",
        "reconcile_required",
        "running",
        "cancel_requested",
        "succeeded",
        "failed",
        "cancelled",
        "unknown",
      ].map((state) => [
        backupRunStateLabel(state as never),
        backupRunStateSeverity(state as never),
      ]),
    ).toEqual([
      ["Wartet auf Startübergabe", "info"],
      ["Abgleich erforderlich", "danger"],
      ["Läuft", "info"],
      ["Abbruch angefordert", "warn"],
      ["Erfolgreich", "success"],
      ["Fehlgeschlagen", "danger"],
      ["Abgebrochen", "secondary"],
      ["Unklar", "danger"],
    ]);
    expect(
      ["failure", "attention_required", "recovery"].map((kind) =>
        backupNotificationKindLabel(kind as never),
      ),
    ).toEqual(["Fehlversuch", "Eingriff erforderlich", "Entwarnung"]);
    expect(
      ["pending", "claimed", "sent"].map((state) => [
        backupNotificationStateLabel(state as never),
        backupNotificationStateSeverity(state as never),
      ]),
    ).toEqual([
      ["Ausstehend", "warn"],
      ["In Zustellung", "info"],
      ["Zugestellt", "success"],
    ]);
    expect(
      [
        "dispatching",
        "requested",
        "ambiguous",
        "definitive_rejection",
        "dispatch_unknown",
      ].map((status) => backupStopAttemptStatusLabel(status as never)),
    ).toEqual([
      "Stop-Aufruf wird übergeben",
      "Stop wurde von PVE angenommen",
      "Stop-Antwort ist mehrdeutig",
      "Stop wurde definitiv abgelehnt",
      "Stop-Übergabe nach Crash unklar",
    ]);
    expect(
      ["starting", "ready", "busy", "degraded", "stopping"].map((status) => [
        operationsWorkerStatusLabel(status as never),
        operationsWorkerStatusSeverity(status as never),
      ]),
    ).toEqual([
      ["Startet", "info"],
      ["Bereit", "success"],
      ["Beschäftigt", "info"],
      ["Beeinträchtigt", "warn"],
      ["Wird beendet", "secondary"],
    ]);
  });
});
