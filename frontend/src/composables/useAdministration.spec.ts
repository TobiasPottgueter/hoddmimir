import { describe, expect, it } from "vitest";

import type { AuditEventType } from "@/api/generated/types.gen";
import { useAdministration } from "./useAdministration";

describe("useAdministration", () => {
  const labels = useAdministration();

  it("übersetzt Rollen, Permissions, Ergebnisse und Sicherheitsblocker geschlossen", () => {
    expect(labels.roleLabel("admin")).toBe("Administrator");
    expect(labels.roleLabel("viewer")).toBe("Leser");
    expect(labels.permissionLabel("security.manage")).toContain("verwalten");
    expect(labels.permissionLabel("future.permission")).toBe(
      "future.permission",
    );
    expect(labels.outcomeLabel("succeeded")).toBe("Erfolgreich");
    expect(labels.outcomeLabel("denied")).toBe("Abgewiesen");
    expect(labels.blockerLabel("self_lockout")).toContain("eigene");
    expect(labels.blockerLabel("last_active_admin")).toContain("letzte");
    expect(labels.blockerLabel("user_missing")).toContain("nicht vorhanden");
    expect(labels.blockerLabel("user_disabled")).toContain("deaktiviert");
    expect(labels.blockerLabel("future_rule")).toContain("future_rule");
  });

  it("liefert für jeden vertraglich möglichen Audit-Typ einen deutschen Text", () => {
    const events: AuditEventType[] = [
      "first_admin_created",
      "user_created",
      "user_updated",
      "user_disabled",
      "role_assigned",
      "role_removed",
      "login_succeeded",
      "login_failed",
      "session_created",
      "session_revoked",
      "target_created",
      "target_updated",
      "target_enabled",
      "target_disabled",
      "policy_created",
      "policy_updated",
      "policy_enabled",
      "policy_disabled",
      "selection_upserted",
      "selection_disabled",
      "guest_override_upserted",
      "guest_override_disabled",
      "connection_created",
      "connection_updated",
      "connection_enabled",
      "connection_disabled",
      "endpoint_created",
      "endpoint_updated",
      "endpoint_disabled",
      "credential_rotated",
    ];

    expect(events.map(labels.eventLabel)).toHaveLength(30);
    expect(
      events.map(labels.eventLabel).every((label) => label.length > 4),
    ).toBe(true);
    expect(labels.formatTimestamp("invalid")).toBe("Ungültiger Zeitwert");
  });
});
