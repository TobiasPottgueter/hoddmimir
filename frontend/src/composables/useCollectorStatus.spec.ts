import { describe, expect, it } from "vitest";

import type { CollectorStatus } from "@/api/generated/types.gen";
import { collectorStatus } from "@/test/fixtures";
import {
  collectorHealth,
  statusBoolean,
  statusNumber,
  statusString,
} from "./useCollectorStatus";

describe("Collector-Status", () => {
  it("liest strikt typisierte Werte", () => {
    const fields = {
      text: "ok",
      number: 2,
      invalid: Number.NaN,
      boolean: false,
    };
    expect(statusString(fields, "text")).toBe("ok");
    expect(statusString(fields, "number")).toBeNull();
    expect(statusNumber(fields, "number")).toBe(2);
    expect(statusNumber(fields, "invalid")).toBeNull();
    expect(statusBoolean(fields, "boolean")).toBe(false);
    expect(statusBoolean(null, "boolean")).toBeNull();
  });

  it("unterscheidet Konfiguration, Heartbeat und Frische", () => {
    expect(collectorHealth(null)).toBe("unconfigured");
    expect(
      collectorHealth({
        ...collectorStatus,
        schedule: { configured: false },
      }),
    ).toBe("unconfigured");
    expect(collectorHealth({ ...collectorStatus, heartbeat: null })).toBe(
      "missing",
    );
    expect(collectorHealth(collectorStatus)).toBe("healthy");
    const stale: CollectorStatus = {
      ...collectorStatus,
      heartbeat: { ...collectorStatus.heartbeat!, fresh: false },
    };
    expect(collectorHealth(stale)).toBe("stale");
  });
});
