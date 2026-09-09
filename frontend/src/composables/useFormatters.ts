const dateFormatter = new Intl.DateTimeFormat("de-DE", {
  dateStyle: "medium",
  timeStyle: "medium",
  timeZone: "UTC",
});

const byteFormatter = new Intl.NumberFormat("de-DE", {
  maximumFractionDigits: 1,
});

export function formatUtc(
  timestamp: string | null,
  empty = "Keine Messung",
): string {
  if (timestamp === null) return empty;
  const date = new Date(timestamp);
  return Number.isNaN(date.getTime())
    ? "Ungültiger Zeitwert"
    : `${dateFormatter.format(date)} UTC`;
}

export function formatBytes(bytes: number | null): string {
  if (bytes === null || !Number.isFinite(bytes) || bytes < 0) return "–";
  if (bytes === 0) return "0 B";

  const units = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"];
  const exponent = Math.min(
    Math.floor(Math.log(bytes) / Math.log(1024)),
    units.length - 1,
  );
  return `${byteFormatter.format(bytes / 1024 ** exponent)} ${units[exponent]}`;
}

export function formatDuration(
  startedAt: string,
  finishedAt: string | null,
): string {
  if (finishedAt === null) return "Läuft";
  const duration = Date.parse(finishedAt) - Date.parse(startedAt);
  if (!Number.isFinite(duration) || duration < 0) return "–";
  return `${byteFormatter.format(duration / 1000)} s`;
}

const relativeFormatter = new Intl.RelativeTimeFormat("de-DE", {
  numeric: "auto",
});
export function formatRelativeTime(timestamp: string, now: number): string {
  const difference = Date.parse(timestamp) - now;
  if (!Number.isFinite(difference)) return "Ungültiger Zeitwert";
  const absolute = Math.abs(difference);
  if (absolute < 60_000)
    return difference > 0
      ? "in weniger als einer Minute"
      : "vor weniger als einer Minute";
  const unit =
    absolute < 3_600_000 ? "minute" : absolute < 86_400_000 ? "hour" : "day";
  const divisor =
    unit === "minute" ? 60_000 : unit === "hour" ? 3_600_000 : 86_400_000;
  return relativeFormatter.format(Math.trunc(difference / divisor), unit);
}
