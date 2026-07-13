import type {
  AdministrationRoleName,
  AuditEventType,
} from "@/api/generated/types.gen";
import { formatUtc } from "@/composables/useFormatters";

const roleLabels: Record<AdministrationRoleName, string> = {
  admin: "Administrator",
  viewer: "Leser",
};

const permissionLabels: Record<string, string> = {
  "inventory.read": "Inventar lesen",
  "backup_configuration.manage": "Backup-Konfiguration verwalten",
  "backup_operations.manage": "Backup-Betrieb verwalten",
  "audit.read": "Audit-Log lesen",
  "security.manage": "Benutzer und Rollen verwalten",
};

const eventLabels: Record<AuditEventType, string> = {
  first_admin_created: "Erster Administrator angelegt",
  user_created: "Benutzer angelegt",
  user_updated: "Benutzer geändert",
  user_disabled: "Benutzer deaktiviert",
  role_assigned: "Rolle zugewiesen",
  role_removed: "Rolle entfernt",
  login_succeeded: "Anmeldung erfolgreich",
  login_failed: "Anmeldung fehlgeschlagen",
  session_created: "Sitzung angelegt",
  session_revoked: "Sitzung widerrufen",
  target_created: "Backup-Ziel angelegt",
  target_updated: "Backup-Ziel geändert",
  target_enabled: "Backup-Ziel aktiviert",
  target_disabled: "Backup-Ziel deaktiviert",
  policy_created: "Policy angelegt",
  policy_updated: "Policy geändert",
  policy_enabled: "Policy aktiviert",
  policy_disabled: "Policy deaktiviert",
  selection_upserted: "Auswahl gespeichert",
  selection_disabled: "Auswahl deaktiviert",
  guest_override_upserted: "Gast-Override gespeichert",
  guest_override_disabled: "Gast-Override deaktiviert",
  connection_created: "Verbindung angelegt",
  connection_updated: "Verbindung geändert",
  connection_enabled: "Verbindung aktiviert",
  connection_disabled: "Verbindung deaktiviert",
  endpoint_created: "Endpoint angelegt",
  endpoint_updated: "Endpoint geändert",
  endpoint_disabled: "Endpoint deaktiviert",
  credential_rotated: "Credential rotiert",
  manual_backup_requested: "Manuelles Backup angefordert",
  backup_cancel_requested: "Backup-Abbruch angefordert",
};

const blockerLabels: Record<string, string> = {
  user_missing: "Der Benutzer ist nicht vorhanden.",
  user_disabled: "Der Benutzer ist bereits deaktiviert.",
  self_lockout:
    "Die eigene Administrationsberechtigung darf nicht entzogen werden.",
  last_active_admin:
    "Der letzte aktive Administrator darf nicht deaktiviert werden.",
};

export function useAdministration() {
  return {
    formatTimestamp: formatUtc,
    roleLabel: (role: AdministrationRoleName) => roleLabels[role],
    permissionLabel: (permission: string) =>
      permissionLabels[permission] ?? permission,
    eventLabel: (event: AuditEventType) => eventLabels[event],
    outcomeLabel: (outcome: "succeeded" | "denied") =>
      outcome === "succeeded" ? "Erfolgreich" : "Abgewiesen",
    blockerLabel: (blocker: string) =>
      blockerLabels[blocker] ?? `Unbekannte Sicherheitsregel: ${blocker}`,
  };
}
