import { describe, expect, it } from "vitest";
import {
  parseQuantity,
  quantityInUnit,
  quantityUnits,
} from "./useExactQuantity";

describe("Exakte Größen und Zeitspannen", () => {
  it("erhält große Bytewerte und Bruchteile ohne Number-Rundung", () => {
    const maximum = "18446744073709551615";
    for (const unit of quantityUnits.bytes) {
      const displayed = quantityInUnit(maximum, unit.factor)!;
      expect(parseQuantity(displayed, unit.factor)).toEqual({
        value: maximum,
        error: null,
      });
    }
    expect(parseQuantity("1,5", 1073741824n).value).toBe("1610612736");
    expect(parseQuantity("1.5", 60n).value).toBe("90");
    expect(parseQuantity(" 0000 ", 1n).value).toBe("0");
    expect(parseQuantity("", 1n).value).toBe("");
    expect(quantityInUnit("", 60n)).toBe("");
    expect(quantityInUnit("3600", 3600n)).toBe("1");
    expect(quantityInUnit("90", 60n)).toBe("1,5");
  });
  it("lehnt ungültige Werte, Überläufe und verlustbehaftete Umrechnungen ab", () => {
    for (const text of [
      "-1",
      "1e3",
      "x",
      "1,2,3",
      "1".repeat(101),
      "18446744073709551616",
      "0,1",
    ]) {
      expect(parseQuantity(text, 1n).error).not.toBeNull();
    }
    expect(parseQuantity("1", 0n).error).not.toBeNull();
    expect(quantityInUnit("1", 60n)).toBeNull();
    expect(quantityInUnit("no", 1n)).toBeNull();
    expect(quantityInUnit("1", 0n)).toBeNull();
  });
});
