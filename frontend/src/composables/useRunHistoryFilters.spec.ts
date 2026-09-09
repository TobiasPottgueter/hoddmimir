import { describe, expect, it } from "vitest";
import {
  canonicalRunTimestamp,
  emptyRunHistoryDraft,
  runHistoryDraftFromQuery,
  validateRunHistoryDraft,
} from "./useRunHistoryFilters";
const id = "11111111-1111-4111-8111-111111111111";
describe("Run history filters", () => {
  it("keeps UTC and microseconds exact and rejects calendar rollovers", () => {
    expect(canonicalRunTimestamp("2026-09-09T12:00:00Z")).toBe(
      "2026-09-09T12:00:00.000000Z",
    );
    expect(canonicalRunTimestamp("2026-09-09T12:00:00.000001Z")).toBe(
      "2026-09-09T12:00:00.000001Z",
    );
    expect(canonicalRunTimestamp("2024-02-29T12:00:00.1Z")).toBe(
      "2024-02-29T12:00:00.100000Z",
    );
    for (const bad of [
      "2026-02-29T00:00:00Z",
      "2026-02-30T00:00:00Z",
      "2026-01-01T24:00:00Z",
      "2026-01-01T00:00:00+02:00",
      "2026-01-01T00:00:00.1234567Z",
      "0999-01-01T00:00:00Z",
      "wrong",
    ])
      expect(canonicalRunTimestamp(bad)).toBeNull();
  });
  it("validates each field without losing other filters and respects submillisecond interval bounds", () => {
    const draft = {
      ...emptyRunHistoryDraft(),
      guestId: id,
      nodeId: id,
      targetId: id,
      vmid: "201",
      search: "München_%!",
      startedFrom: "2026-09-09T00:00:00Z",
      startedBefore: "2026-09-09T00:00:00.000001Z",
    };
    const result = validateRunHistoryDraft(draft);
    expect(result.errors).toEqual({});
    expect(result.filters.vmid).toBe(201);
    expect(result.filters.guestId).toBe(id);
    expect(result.filters.search).toBe("München_%!");
    expect(
      validateRunHistoryDraft({ ...draft, startedBefore: draft.startedFrom })
        .errors,
    ).toHaveProperty("runs-startedBefore");
    expect(validateRunHistoryDraft(emptyRunHistoryDraft())).toEqual({
      filters: {},
      errors: {},
    });
    for (const vmid of ["0", "-1", "01", "1.5", "2147483648"])
      expect(validateRunHistoryDraft({ ...draft, vmid }).errors).toHaveProperty(
        "runs-vmid",
      );
    expect(
      validateRunHistoryDraft({ ...draft, vmid: "2147483647" }).errors,
    ).toEqual({});
    expect(
      validateRunHistoryDraft({ ...draft, search: "ä".repeat(96) }).errors,
    ).toHaveProperty("runs-search");
    expect(
      validateRunHistoryDraft({ ...draft, search: "a\nb" }).errors,
    ).toHaveProperty("runs-search");
    for (const key of ["guestId", "nodeId", "targetId"] as const)
      expect(
        validateRunHistoryDraft({ ...draft, [key]: "wrong" }).errors,
      ).toHaveProperty(`runs-${key}`);
    expect(
      validateRunHistoryDraft({ ...draft, startedFrom: "wrong" }).errors,
    ).toHaveProperty("runs-startedFrom");
  });
  it("marks invalid shared parameters as errors instead of silently broadening the result", () => {
    const invalid = runHistoryDraftFromQuery({
      guestId: [id, id],
      startedFrom: "wrong",
      vmid: "",
      targetId: null,
    });
    expect(Object.keys(invalid.errors)).toHaveLength(4);
    const valid = runHistoryDraftFromQuery({
      guestId: id,
      vmid: "201",
      search: "shared",
      startedFrom: "2026-01-01T00:00:00Z",
    });
    expect(valid.errors).toEqual({});
    expect(valid.draft.guestId).toBe(id);
    expect(valid.draft.nodeId).toBe("");
  });
});
