import type { LocationQuery } from "vue-router";
import type { RunHistoryFilters } from "@/api/operationsApi";

export const runFilterKeys = [
  "guestId",
  "nodeId",
  "targetId",
  "vmid",
  "search",
  "startedFrom",
  "startedBefore",
] as const;
export type RunHistoryDraft = Record<(typeof runFilterKeys)[number], string>;
export const emptyRunHistoryDraft = (): RunHistoryDraft => ({
  guestId: "",
  nodeId: "",
  targetId: "",
  vmid: "",
  search: "",
  startedFrom: "",
  startedBefore: "",
});

/** Calendar validation with microsecond precision; no browser-local timezone conversion. */
export function canonicalRunTimestamp(value: string): string | null {
  const match =
    /^([1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z$/.exec(
      value,
    );
  if (!match) return null;
  const second = `${match[1]}Z`;
  const date = new Date(second);
  if (
    !Number.isFinite(date.getTime()) ||
    date.toISOString().slice(0, 19) !== match[1]
  )
    return null;
  return `${match[1]}.${(match[2] ?? "").padEnd(6, "0")}Z`;
}
export function validateRunHistoryDraft(draft: RunHistoryDraft): {
  filters: RunHistoryFilters;
  errors: Record<string, string>;
} {
  const filters: RunHistoryFilters = {};
  const errors: Record<string, string> = {};
  for (const key of ["guestId", "nodeId", "targetId"] as const) {
    if (!draft[key]) continue;
    if (
      !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(
        draft[key],
      )
    )
      errors[`runs-${key}`] =
        "Bitte eine gültige Auswahl verwenden oder den Filter zurücksetzen.";
    else filters[key] = draft[key];
  }
  if (draft.vmid) {
    if (
      !/^[1-9][0-9]{0,9}$/.test(draft.vmid) ||
      Number(draft.vmid) > 2147483647
    )
      errors["runs-vmid"] =
        "VMID muss eine ganze Zahl zwischen 1 und 2147483647 sein.";
    else filters.vmid = Number(draft.vmid);
  }
  if (draft.search) {
    if (
      !draft.search.trim() ||
      new TextEncoder().encode(draft.search).length > 190 ||
      /[\u0000-\u001f\u007f]/u.test(draft.search)
    )
      errors["runs-search"] =
        "Der Gastname darf höchstens 190 UTF-8-Bytes und keine Steuerzeichen enthalten.";
    else filters.search = draft.search;
  }
  for (const key of ["startedFrom", "startedBefore"] as const) {
    if (!draft[key]) continue;
    const canonical = canonicalRunTimestamp(draft[key]);
    if (!canonical)
      errors[`runs-${key}`] =
        "Bitte einen gültigen UTC-Zeitpunkt eingeben, z. B. 2026-09-09T00:00:00Z.";
    else filters[key] = canonical;
  }
  if (
    filters.startedFrom &&
    filters.startedBefore &&
    filters.startedFrom >= filters.startedBefore
  )
    errors["runs-startedBefore"] =
      "Das Ende muss nach dem Beginn des Zeitraums liegen.";
  return { filters, errors };
}
export function runHistoryDraftFromQuery(query: LocationQuery): {
  draft: RunHistoryDraft;
  errors: Record<string, string>;
} {
  const draft = emptyRunHistoryDraft();
  const errors: Record<string, string> = {};
  for (const key of runFilterKeys) {
    if (query[key] === undefined) continue;
    if (typeof query[key] !== "string" || query[key] === "")
      errors[`runs-${key}`] =
        "Der Link enthält einen ungültigen Filter. Bitte neu auswählen oder zurücksetzen.";
    else draft[key] = query[key];
  }
  return {
    draft,
    errors: { ...validateRunHistoryDraft(draft).errors, ...errors },
  };
}
