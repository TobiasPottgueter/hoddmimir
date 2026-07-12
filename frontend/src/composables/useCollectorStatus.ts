import type { CollectorStatus } from "@/api/generated/types.gen";

export type CollectorHealth = "unconfigured" | "missing" | "healthy" | "stale";

export function statusString(
  record: Record<string, unknown> | null,
  key: string,
): string | null {
  const value = record?.[key];
  return typeof value === "string" ? value : null;
}

export function statusNumber(
  record: Record<string, unknown> | null,
  key: string,
): number | null {
  const value = record?.[key];
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

export function statusBoolean(
  record: Record<string, unknown> | null,
  key: string,
): boolean | null {
  const value = record?.[key];
  return typeof value === "boolean" ? value : null;
}

export function collectorHealth(
  status: CollectorStatus | null,
): CollectorHealth {
  if (
    status === null ||
    statusBoolean(status.schedule, "configured") !== true
  ) {
    return "unconfigured";
  }
  if (status.heartbeat === null) return "missing";
  return statusBoolean(status.heartbeat, "fresh") === true
    ? "healthy"
    : "stale";
}
