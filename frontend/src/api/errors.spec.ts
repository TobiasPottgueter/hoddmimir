import { describe, expect, it } from "vitest";

import { apiErrorMessage, withHttpStatus } from "./errors";

describe("apiErrorMessage", () => {
  it("übersetzt Netzwerk-, Berechtigungs- und unbekannte Fehler", () => {
    expect(apiErrorMessage(new TypeError("fetch failed"))).toContain(
      "nicht erreichbar",
    );
    expect(apiErrorMessage(new Error("HTTP 403 forbidden"))).toContain(
      "Berechtigung",
    );
    expect(apiErrorMessage(new Error(" permission denied "))).toContain(
      "Berechtigung",
    );
    expect(apiErrorMessage(new Error("kaputt"))).toBe("kaputt");
    expect(apiErrorMessage(new Error("  "))).toContain("nicht geladen");
    expect(apiErrorMessage("kaputt")).toContain("nicht geladen");
  });

  it("erkennt reale Plain-Object-Fehler anhand des HTTP-Status", () => {
    const payload = { error: { code: "forbidden", message: "denied" } };
    expect(
      apiErrorMessage(
        withHttpStatus(payload, new Response(null, { status: 401 })),
      ),
    ).toContain("Anmeldung");
    expect(
      apiErrorMessage(
        withHttpStatus(payload, new Response(null, { status: 403 })),
      ),
    ).toContain("Berechtigung");
    expect(withHttpStatus(payload, undefined)).toBe(payload);
    expect(apiErrorMessage({ error: { code: "unauthorized" } })).toContain(
      "Anmeldung",
    );
    expect(apiErrorMessage({ error: { code: "permission_denied" } })).toContain(
      "Berechtigung",
    );
  });
});
