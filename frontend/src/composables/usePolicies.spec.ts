import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";

import type { PolicySelectionEntry } from "@/api/generated/types.gen";
import { OTHER_UUID } from "@/test/fixtures";
import { usePoliciesStore } from "@/stores/policies";
import {
  policyBlockerLabel,
  policyScopeLabel,
  policyStatusLabel,
  retentionSummary,
  usePolicies,
} from "./usePolicies";

const entry = (kind: PolicySelectionEntry["kind"]): PolicySelectionEntry => ({
  id: OTHER_UUID,
  revision: 1,
  status: "active",
  kind,
  scope: kind === "guest_override" ? "guest" : "cluster",
  connectionId: null,
  clusterId: null,
  nodeId: null,
  guestId: null,
  subjectName: null,
  selectionValue: null,
  mode: null,
  compression: null,
  desiredRetention: null,
  disabledAt: null,
});

describe("usePolicies", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("liefert geschlossene deutsche Labels und Retention", () => {
    expect(policyStatusLabel("draft")).toBe("Entwurf");
    expect(policyBlockerLabel("executor_evidence_missing")).toContain(
      "Executor",
    );
    expect(policyScopeLabel("guest")).toBe("Gast");
    expect(
      retentionSummary({
        legacyMaxFiles: null,
        keepAll: false,
        keepLast: 3,
        keepHourly: null,
        keepDaily: 7,
        keepWeekly: null,
        keepMonthly: null,
        keepYearly: null,
      }),
    ).toBe("Letzte: 3 · Täglich: 7");
    expect(policyStatusLabel("enabled")).toBe("Aktiviert");
    expect(policyStatusLabel("disabled")).toBe("Deaktiviert");
    expect(policyBlockerLabel("configuration_incomplete")).toContain(
      "unvollständig",
    );
    for (const [scope, label] of [
      ["global", "Global"],
      ["connection", "Verbindung"],
      ["cluster", "Cluster"],
      ["node", "Node"],
    ] as const) {
      expect(policyScopeLabel(scope)).toBe(label);
    }
    expect(retentionSummary(null)).toBe("Nicht festgelegt");
    expect(
      retentionSummary({
        legacyMaxFiles: null,
        keepAll: false,
        keepLast: null,
        keepHourly: null,
        keepDaily: null,
        keepWeekly: null,
        keepMonthly: null,
        keepYearly: null,
      }),
    ).toBe("Keine Werte gesetzt");
    expect(
      retentionSummary({
        legacyMaxFiles: 1,
        keepAll: true,
        keepLast: 2,
        keepHourly: 3,
        keepDaily: 4,
        keepWeekly: 5,
        keepMonthly: 6,
        keepYearly: 7,
      }),
    ).toBe(
      "Alle behalten · Legacy: 1 · Letzte: 2 · Stündlich: 3 · Täglich: 4 · Wöchentlich: 5 · Monatlich: 6 · Jährlich: 7",
    );
  });

  it("trennt Zuweisungen von Guest-Overrides reaktiv", () => {
    const store = usePoliciesStore();
    const { assignments, guestOverrides } = usePolicies();
    store.selectionItems = [entry("assignment"), entry("guest_override")];
    expect(assignments.value).toHaveLength(1);
    expect(guestOverrides.value).toHaveLength(1);
  });
});
