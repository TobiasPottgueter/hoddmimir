import { afterEach, describe, expect, it } from "vitest";

import router from "./router";

describe("Browser-Titel", () => {
  afterEach(() => {
    document.title = "";
  });

  it("kombiniert den Seitentitel mit dem Displaynamen Hoddmímir", async () => {
    await router.push("/systems");
    await router.isReady();

    expect(document.title).toBe("Systeme · Hoddmímir");
  });
});
