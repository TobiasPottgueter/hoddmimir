import { describe, expect, it } from "vitest";

import {
  formatBytes,
  formatDuration,
  formatUtc,
  formatRelativeTime,
} from "./useFormatters";

describe("Formatierer", () => {
  it("formatiert UTC-Zeitwerte und fängt leere oder ungültige Werte ab", () => {
    expect(formatUtc(null)).toBe("Keine Messung");
    expect(formatUtc("unbrauchbar")).toBe("Ungültiger Zeitwert");
    expect(formatUtc("2026-07-12T10:00:00Z")).toContain("10:00:00");
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

it("kennzeichnet UTC und relative Zeitgrenzen eindeutig", () => {
  const now = Date.parse("2026-09-09T12:00:00Z");
  expect(formatUtc("2026-09-09T12:00:00Z")).toMatch(/12:00:00 UTC$/);
  expect(formatUtc(null, "Nicht geplant")).toBe("Nicht geplant");
  expect(formatRelativeTime("invalid", now)).toBe("Ungültiger Zeitwert");
  expect(formatRelativeTime("2026-09-09T12:00:10Z", now)).toContain(
    "in weniger",
  );
  expect(formatRelativeTime("2026-09-09T11:59:50Z", now)).toContain(
    "vor weniger",
  );
  expect(formatRelativeTime("2026-09-09T11:58:00Z", now)).toBe("vor 2 Minuten");
  expect(formatRelativeTime("2026-09-09T10:00:00Z", now)).toBe("vor 2 Stunden");
  expect(formatRelativeTime("2026-09-07T12:00:00Z", now)).toBe("vorgestern");
});
