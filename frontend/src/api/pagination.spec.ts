import { describe, expect, it } from "vitest";

import { pageContinuation } from "./pagination";

describe("cursor pagination adapter", () => {
  it("reicht den opaken API-Cursor unverändert weiter", () => {
    expect(
      pageContinuation({
        limit: 1,
        count: 1,
        hasMore: true,
        nextCursor: "opaque-api-cursor",
      }),
    ).toBe("opaque-api-cursor");
  });

  it("beendet vollständige Seitenfolgen eindeutig", () => {
    const page = {
      limit: 25,
      count: 0,
      hasMore: false,
      nextCursor: null,
    };
    expect(pageContinuation(page)).toBeUndefined();
  });

  it.each([
    () =>
      pageContinuation({
        limit: 1,
        count: 1,
        hasMore: true,
        nextCursor: null,
      }),
    () =>
      pageContinuation({
        limit: 1,
        count: 1,
        hasMore: false,
        nextCursor: "unexpected",
      }),
    () =>
      pageContinuation(
        { limit: 1, count: 1, hasMore: true, nextCursor: "same" },
        "same",
      ),
  ])(
    "weist inkonsistente oder fremde Cursor fail-closed zurück",
    (operation) => {
      expect(operation).toThrow();
    },
  );
});
