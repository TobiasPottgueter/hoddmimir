const dateFormatter = new Intl.DateTimeFormat("de-DE", {
  dateStyle: "medium",
  timeStyle: "medium",
  timeZone: "UTC",
});

const byteFormatter = new Intl.NumberFormat("de-DE", {
  maximumFractionDigits: 1,
});

export function formatUtc(timestamp: string | null): string {
  if (timestamp === null) return "Keine Messung";
  const date = new Date(timestamp);
  return Number.isNaN(date.getTime())
    ? "Ungültiger Zeitwert"
    : dateFormatter.format(date);
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
