export const quantityUnits = {
  bytes: [
    { label: "Bytes", factor: 1n },
    { label: "KiB", factor: 1024n },
    { label: "MiB", factor: 1048576n },
    { label: "GiB", factor: 1073741824n },
    { label: "TiB", factor: 1099511627776n },
  ],
  duration: [
    { label: "Sekunden", factor: 1n },
    { label: "Minuten", factor: 60n },
    { label: "Stunden", factor: 3600n },
    { label: "Tage", factor: 86400n },
  ],
} as const;

/** Convert decimal input without ever passing a byte count through Number. */
export function parseQuantity(
  input: string,
  factor: bigint,
): { value: string; error: null } | { value: null; error: string } {
  const text = input.trim().replace(",", ".");
  if (text === "") return { value: "", error: null };
  if (factor <= 0n || text.length > 100 || !/^\d+(?:\.\d+)?$/u.test(text)) {
    return { value: null, error: "Bitte eine nicht negative Zahl eingeben." };
  }
  const [whole, fraction = ""] = text.split(".");
  const divisor = 10n ** BigInt(fraction.length);
  const numerator = BigInt(whole! + fraction) * factor;
  if (numerator % divisor !== 0n)
    return {
      value: null,
      error:
        "Der Wert muss ganzen Bytes bzw. Sekunden entsprechen. Es wird nicht gerundet.",
    };
  const value = numerator / divisor;
  if (value > 18446744073709551615n)
    return {
      value: null,
      error: "Der Wert überschreitet den zulässigen Wertebereich.",
    };
  return { value: value.toString(), error: null };
}

/** null means the chosen unit would require rounding (for example 1 s / 60). */
export function quantityInUnit(
  canonical: string,
  factor: bigint,
): string | null {
  if (canonical === "") return "";
  if (!/^\d+$/u.test(canonical) || factor <= 0n) return null;
  const value = BigInt(canonical);
  let remainder = value % factor;
  let fraction = "";
  const seen = new Set<bigint>();
  while (remainder !== 0n) {
    if (seen.has(remainder)) return null;
    seen.add(remainder);
    remainder *= 10n;
    fraction += (remainder / factor).toString();
    remainder %= factor;
  }
  return (value / factor).toString() + (fraction ? `,${fraction}` : "");
}
