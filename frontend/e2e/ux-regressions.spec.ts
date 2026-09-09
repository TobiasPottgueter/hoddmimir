import { expect, test } from "@playwright/test";

const policyId = "11111111-1111-4111-8111-111111111111";
const emptyPage = {
  items: [],
  page: { limit: 25, count: 0, hasMore: false, nextCursor: null },
};
const policy = {
  id: policyId,
  revision: 1,
  status: "draft",
  displayName: "UX Policy",
  connectionId: policyId,
  connectionName: "PVE",
  clusterId: "22222222-2222-4222-8222-222222222222",
  clusterName: "Cluster",
  targetId: null,
  targetName: null,
  priority: null,
  mode: null,
  compression: null,
  maximumAgeSeconds: null,
  bytesWrittenThreshold: null,
  cooldownSeconds: null,
  schedule: null,
  desiredRetention: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: [],
  disabledAt: null,
  canEnable: false,
  blockers: [],
};

test.beforeEach(async ({ page }) => {
  await page.route("**/api/v1/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body: unknown = emptyPage;
    if (path.endsWith("/auth/session"))
      body = {
        user: {
          id: policyId,
          username: "ux-test",
          permissions: ["inventory.read", "backup_configuration.manage"],
        },
        csrfToken: "synthetic-test",
        idleExpiresAt: "2099-01-01T00:00:00Z",
        absoluteExpiresAt: "2099-01-01T00:00:00Z",
      };
    if (path === "/api/v1/policies")
      body = {
        ...emptyPage,
        items: [policy],
        page: { ...emptyPage.page, count: 1 },
      };
    if (path.endsWith("/notifications/health"))
      body = {
        byState: { pending: 0, claimed: 0, sent: 0 },
        oldestUnsentAt: null,
        nextDeliveryAttemptAt: null,
        lastErrorCode: null,
      };
    // Fail closed: an unhandled mutation is a test error, never a real backend call.
    expect(route.request().method()).toBe("GET");
    await route.fulfill({ json: body });
  });
});

test("mobile navigation traps focus, closes with Escape and focuses the destination", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto("/inventory");
  const toggle = page.getByRole("button", {
    name: "Navigation öffnen",
    exact: true,
  });
  await expect(toggle).toBeVisible();
  await expect(page.locator("main")).toBeFocused();
  await page.locator(".skip-link").focus();
  await page.keyboard.press("Tab");
  await expect(toggle).toBeFocused();
  await toggle.click();
  const close = page.locator(".navigation-close");
  await expect(close).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(page.locator("nav a").last()).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(close).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(toggle).toBeFocused();
  await expect(toggle).toHaveAttribute("aria-expanded", "false");
  await toggle.click();
  await page.getByRole("link", { name: "Läufe", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Backup-Läufe", exact: true }),
  ).toBeVisible();
  await expect(page.locator("main")).toBeFocused();
});

test("shared run filters survive reload and errors never masquerade as empty results", async ({
  page,
}) => {
  await page.goto("/runs?state=failed");
  await expect(
    page.getByRole("combobox", { name: "Laufzustand" }),
  ).toContainText("Fehlgeschlagen");
  await page.reload();
  await expect(
    page.getByRole("combobox", { name: "Laufzustand" }),
  ).toContainText("Fehlgeschlagen");
  await expect(
    page.getByText("Keine Backup-Läufe für diesen Filter.", { exact: true }),
  ).toBeVisible();
  await page.route("**/api/v1/operations/runs?*", (route) =>
    route.fulfill({ status: 503, json: { error: { code: "unavailable" } } }),
  );
  await page
    .getByRole("button", { name: "Anzeige aktualisieren", exact: true })
    .click();
  await expect(
    page.getByText("Die Daten konnten nicht geladen werden.", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByText("Keine Backup-Läufe für diesen Filter.", { exact: true }),
  ).toHaveCount(0);
});

test("an empty policy selection remains editable and invalid form submission focuses the field summary", async ({
  page,
}) => {
  await page.goto(`/policies?policy=${policyId}`);
  await expect(
    page.getByRole("button", { name: "Auswahlregel speichern", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Bearbeiten", exact: true }).click();
  await page.getByLabel("Anzeigename", { exact: false }).fill("");
  await page
    .getByRole("button", { name: "Policy speichern", exact: true })
    .click();
  const summary = page.getByRole("alert", {
    name: "Bitte die Eingaben prüfen",
  });
  await expect(summary).toBeFocused();
  await summary
    .getByRole("link", { name: "Ein Anzeigename ist erforderlich." })
    .click();
  await expect(page.locator("#policy-name")).toBeFocused();
  await expect(page.locator("#policy-name")).toHaveAttribute(
    "aria-invalid",
    "true",
  );
});

test("extended history filters survive pagination, reload and back; invalid filters block reads", async ({
  page,
}) => {
  const requests: URL[] = [];
  await page.route("**/api/v1/operations/runs?*", async (route) => {
    const url = new URL(route.request().url());
    requests.push(url);
    const more = !url.searchParams.has("cursor");
    await route.fulfill({
      json: {
        items: [
          {
            id: more ? policyId : "22222222-2222-4222-8222-222222222222",
            guestName: more ? "Erster Gast" : "Zweiter Gast",
            guestType: "qemu",
            vmid: 201,
            nodeName: "Node A",
            targetName: "PBS",
            state: "succeeded",
            attempt: 1,
            startedAt: "2026-09-09T00:00:00Z",
          },
        ],
        page: {
          limit: 1,
          count: 1,
          hasMore: more,
          nextCursor: more ? "synthetic-cursor" : null,
        },
      },
    });
  });
  const params = new URLSearchParams({
    state: "succeeded",
    guestId: policyId,
    nodeId: policyId,
    targetId: policyId,
    vmid: "201",
    search: "Gast",
    startedFrom: "2026-09-09T00:00:00Z",
    startedBefore: "2026-09-10T00:00:00Z",
  });
  await page.goto(`/runs?${params}`);
  await expect(page.getByText("Erster Gast", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Weitere Läufe", exact: true })
    .click();
  await expect(page.getByText("Zweiter Gast", { exact: true })).toBeVisible();
  const last = requests.at(-1)!;
  expect(last.searchParams.get("cursor")).toBe("synthetic-cursor");
  for (const key of [
    "guestId",
    "nodeId",
    "targetId",
    "vmid",
    "search",
    "state",
  ])
    expect(last.searchParams.get(key)).toBe(params.get(key));
  expect(last.searchParams.get("startedFrom")).toBe(
    "2026-09-09T00:00:00.000000Z",
  );
  expect(last.searchParams.get("startedBefore")).toBe(
    "2026-09-10T00:00:00.000000Z",
  );
  const form = page.getByRole("form", { name: "Backup-Läufe filtern" });
  await form.getByLabel("Gastname enthält").fill("Neuer Gast");
  await form
    .getByRole("button", { name: "Filter anwenden", exact: true })
    .click();
  await expect
    .poll(() => requests.at(-1)!.searchParams.get("search"))
    .toBe("Neuer Gast");
  expect(requests.at(-1)!.searchParams.has("cursor")).toBe(false);
  await expect(page.getByText("Zweiter Gast", { exact: true })).toHaveCount(0);
  await page.reload();
  await expect(form.getByLabel("Gastname enthält")).toHaveValue("Neuer Gast");
  await page.goBack();
  await expect(form.getByLabel("Gastname enthält")).toHaveValue("Gast");
  await expect
    .poll(() => requests.at(-1)!.searchParams.get("search"))
    .toBe("Gast");
  await form
    .getByRole("button", { name: "Filter zurücksetzen", exact: true })
    .click();
  await expect
    .poll(() => requests.at(-1)!.searchParams.has("guestId"))
    .toBe(false);
  const count = requests.length;
  await form.getByLabel("VMID", { exact: true }).fill("0");
  await form
    .getByRole("button", { name: "Filter anwenden", exact: true })
    .click();
  await expect(form.locator("#runs-vmid-error")).toBeVisible();
  expect(requests).toHaveLength(count);
  await page.goto("/runs?startedFrom=invalid");
  await expect(page.locator("#runs-startedFrom-error")).toContainText(
    "gültigen UTC-Zeitpunkt",
  );
  await expect(page.getByText("Erster Gast", { exact: true })).toHaveCount(0);
  expect(requests).toHaveLength(count);
});
