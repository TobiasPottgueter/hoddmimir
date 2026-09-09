import { describe, expect, it } from "vitest";

import { resource } from "@/test/fixtures";
import {
  booleanAttribute,
  numberAttribute,
  stringAttribute,
  stringListAttribute,
} from "./useResourceAttributes";

describe("Ressourcenattribute", () => {
  const item = resource("pve_storage", {
    name: "pbs",
    count: 3,
    invalidNumber: Number.NaN,
    enabled: true,
    list: ["backup", "iso"],
    badList: ["backup", 1],
  });

  it("liest ausschließlich Attribute des erwarteten Typs", () => {
    expect(stringAttribute(item, "name")).toBe("pbs");
    expect(stringAttribute(item, "count")).toBeNull();
    expect(numberAttribute(item, "count")).toBe(3);
    expect(numberAttribute(item, "invalidNumber")).toBeNull();
    expect(booleanAttribute(item, "enabled")).toBe(true);
    expect(booleanAttribute(item, "name")).toBeNull();
    expect(stringListAttribute(item, "list")).toEqual(["backup", "iso"]);
    expect(stringListAttribute(item, "badList")).toEqual([]);
    expect(stringListAttribute(item, "missing")).toEqual([]);
  });
});
