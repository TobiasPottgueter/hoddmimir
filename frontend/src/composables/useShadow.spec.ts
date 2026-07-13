import { beforeEach, describe, expect, it } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { useShadowStore } from "@/stores/shadow";
import {
  shadowGateLabel,
  shadowDetailLabel,
  shadowOutcomeLabel,
  shadowReasonLabel,
  shadowScopeLabel,
  useShadow,
} from "./useShadow";

describe("useShadow", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("liefert vollständig geschlossene erklärbare Labels", () => {
    expect(shadowGateLabel("guest_enabled")).toBe("Gast aktiviert");
    expect(shadowOutcomeLabel("eligible")).toBe("Geeignet");
    expect(shadowOutcomeLabel("blocked")).toBe("Blockiert");
    expect(shadowOutcomeLabel("not_due")).toBe("Nicht fällig");
    expect(shadowOutcomeLabel("deduplicated")).toBe("Dedupliziert");
    expect(shadowReasonLabel(null)).toBe("Kein Sicherungsgrund");
    expect(shadowReasonLabel("never_backed_up")).toContain("nie gesichert");
    expect(shadowScopeLabel("pbs_mapping")).toBe("PBS-Zuordnung");
    expect(shadowDetailLabel("active_request_exists")).toBe(
      "Aktive Anforderung vorhanden",
    );
  });

  it("trennt bestandene und blockierende Gates reaktiv", () => {
    const store = useShadowStore();
    const { passedGates, blockedGates } = useShadow();
    expect(passedGates.value).toEqual([]);
    expect(blockedGates.value).toEqual([]);
    store.detail = {
      id: "d",
      evaluationId: "e",
      guestId: "g",
      nodeId: null,
      outcome: "blocked",
      reason: null,
      priority: null,
      policyId: "p",
      policyRevision: 1,
      targetId: "t",
      targetRevision: 1,
      completedAt: "now",
      gates: [
        {
          position: 1,
          code: "guest_enabled",
          passed: true,
          scope: "guest",
          subjectId: "g",
          observedAt: null,
          detailCode: "passed",
        },
        {
          position: 2,
          code: "capacity_fresh",
          passed: false,
          scope: "capacity",
          subjectId: "t",
          observedAt: null,
          detailCode: "stale",
        },
      ],
    };
    expect(passedGates.value).toHaveLength(1);
    expect(blockedGates.value).toHaveLength(1);
  });
});
