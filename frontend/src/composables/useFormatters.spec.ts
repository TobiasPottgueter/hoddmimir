import { describe, expect, it } from "vitest";

import { formatBytes, formatDuration, formatUtc } from "./useFormatters";

describe("Formatierer", () => {
  it("formatiert UTC-Zeitwerte und fängt leere oder ungültige Werte ab", () => {
    expect(formatUtc(null)).toBe("Keine Messung");
    expect(formatUtc("unbrauchbar")).toBe("Ungültiger Zeitwert");
    expect(formatUtc("2026-07-12T10:00:00Z")).toContain("2026");
  });

  it("formatiert Bytes über alle relevanten Grenzen", () => {
    expect(formatBytes(null)).toBe("–");
    expect(formatBytes(Number.NaN)).toBe("–");
    expect(formatBytes(-1)).toBe("–");
    expect(formatBytes(0)).toBe("0 B");
    expect(formatBytes(1024)).toBe("1 KiB");
    expect(formatBytes(1024 ** 7)).toContain("PiB");
  });

  it("formatiert Laufzeiten und ungültige Grenzen", () => {
    expect(formatDuration("2026-07-12T10:00:00Z", null)).toBe("Läuft");
    expect(formatDuration("invalid", "invalid")).toBe("–");
    expect(formatDuration("2026-07-12T10:01:00Z", "2026-07-12T10:00:00Z")).toBe(
      "–",
    );
    expect(formatDuration("2026-07-12T10:00:00Z", "2026-07-12T10:00:01Z")).toBe(
      "1 s",
    );
  });
});
