import { expect, test, type APIResponse } from "@playwright/test";

import {
  expectNoManualOperations,
  field,
  login,
  QA_VIEWER,
  selectOption,
} from "./support";

test.describe("authentifizierte Konfigurationsflüsse", () => {
  test("führt Operations-Queue, Cancel, Laufdetails, Meldung und Audit end-to-end", async ({
    page,
  }) => {
    let qemuTemplate: Record<string, unknown> | null = null;
    await page.route("**/api/v1/inventory/resources**", async (route) => {
      const url = new URL(route.request().url());
      if (url.searchParams.get("guestType") !== "qemu") {
        await route.continue();
        return;
      }
      if (url.searchParams.get("cursor") === "e2e-qemu-next") {
        expect(qemuTemplate).not.toBeNull();
        await route.fulfill({
          json: {
            items: [
              {
                ...qemuTemplate,
                id: "50000000-0000-4000-8000-000000000999",
                displayName: "qa-qemu-page-101",
                attributes: {
                  ...(qemuTemplate?.attributes as Record<string, unknown>),
                  vmid: 999,
                },
              },
            ],
            page: {
              limit: 100,
              count: 1,
              hasMore: false,
              nextCursor: null,
            },
          },
        });
        return;
      }
      const response = await route.fetch();
      const body = (await response.json()) as {
        items: Array<Record<string, unknown>>;
        page: Record<string, unknown>;
      };
      qemuTemplate = body.items[0] ?? null;
      await route.fulfill({
        response,
        json: {
          ...body,
          page: {
            ...body.page,
            hasMore: true,
            nextCursor: "e2e-qemu-next",
          },
        },
      });
    });
    await login(page);
    await page.goto("/queue");
    const form = page.locator("form.operation-command");
    await selectOption(form, "Aktivierte Policy", "QA Existing Policy");
    await field(form, "VM oder CT").getByRole("combobox").click();
    await expect(
      page.getByRole("option").filter({ hasText: "qa-qemu-page-101" }),
    ).toBeVisible();
    await page.getByRole("option").filter({ hasText: "qa-qemu-101" }).click();
    const requestResponse = page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/v1/operations/requests") &&
        response.request().method() === "POST",
    );
    await form.getByRole("button", { name: "Backup anfordern" }).click();
    const response = await requestResponse;
    expect(response.status(), await response.text()).toBe(201);
    const queued = page
      .locator("article.shadow-evaluation")
      .filter({ hasText: "qa-qemu-101" });
    await expect(queued).toContainText("Wartend");
    await queued.getByRole("button", { name: "Abbrechen" }).click();
    const confirmCancel = page.getByRole("button", {
      name: "Abbruch bestätigen",
    });
    await confirmCancel.focus();
    await page.keyboard.press("Enter");
    await expect(
      page
        .locator("article.shadow-evaluation")
        .filter({ hasText: "qa-qemu-101" }),
    ).toContainText("Abgebrochen");

    await page.goto("/runs");
    await expect(
      page.getByRole("heading", { name: "Matrix-Meldungen" }),
    ).toBeVisible();
    await expect(page.getByText(/Entwarnung · Versuch 1/)).toBeVisible();
    await page.getByText("qa-lxc-201", { exact: true }).first().click();
    await expect(page.getByText("QA sanitized backup log")).toBeVisible();
    await expect(page.getByText(/UPID:qa-node-b/)).toBeVisible();

    await page.goto("/");
    await expect(page.getByText("Heartbeat veraltet")).toBeVisible();
    await expect(page.getByText("Kein Heartbeat vorhanden.")).toHaveCount(1);
    await expect(page.getByText("Manuelles Backup angefordert")).toBeVisible();

    await page.goto("/administration");
    await page.getByRole("button", { name: /Worker & Runtime/ }).click();
    await expect(page.getByText("Heartbeat veraltet")).toBeVisible();
    await expect(page.getByText("Kein Heartbeat vorhanden.")).toBeVisible();
    await expect(page.getByText("Runtime-Readiness")).toBeVisible();

    await page.goto("/shadow");
    await page.getByRole("combobox", { name: "Grund" }).click();
    await page.getByRole("option", { name: "Noch nie gesichert" }).click();
    await page.getByRole("button", { name: "Filter anwenden" }).click();
    await expect(page.getByText("Priorität 300")).toBeVisible();
  });
  test("weist ungültige Zugangsdaten zurück", async ({ page }) => {
    await page.goto("/login");
    await page.getByLabel("Benutzername").fill("qa-admin");
    await page.locator('input[type="password"]').fill("definitely-wrong");
    await page.getByRole("button", { name: "Anmelden" }).click();

    await expect(page.getByText("Anmeldung fehlgeschlagen.")).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });

  test("erzwingt read-only RBAC für den Viewer", async ({ page }) => {
    await login(page, QA_VIEWER);

    await page.goto("/backup-targets");
    await expect(page.getByText("Diese Ansicht ist read-only.")).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Backupziel anlegen" }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Als Ziel konfigurieren" }),
    ).toHaveCount(0);

    await page.goto("/policies");
    await expect(page.getByText("Diese Ansicht ist read-only.")).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Policy anlegen" }),
    ).toHaveCount(0);
    await expectNoManualOperations(page);
  });

  test("konfiguriert einen inventarbasierten Backupziel-Kandidaten", async ({
    page,
  }) => {
    await login(page);
    await page.goto("/backup-targets");

    const candidate = page
      .locator(".candidate-configuration-card")
      .filter({ hasText: "qa-backup" });
    await expect(candidate).toContainText("qa-node-a");
    await expect(candidate).toContainText("qa-node-b");
    await expect(candidate).toContainText("Nicht aktivierbar");
    await candidate
      .getByRole("button", { name: "Als Ziel konfigurieren" })
      .click();

    const form = page.getByRole("form", { name: "Backupziel konfigurieren" });
    await field(form, "Anzeigename").locator("input").fill("QA Browser Target");
    await expect(
      field(form, "Erkannter PVE-Storage").getByRole("combobox"),
    ).toContainText("qa-backup");
    await expect(
      field(form, "Erlaubte Nodes aus Storage-Evidenz"),
    ).toContainText("qa-node-a");
    await expect(
      field(form, "Erlaubte Nodes aus Storage-Evidenz"),
    ).toContainText("qa-node-b");
    await form.getByRole("button", { name: "Backupziel speichern" }).click();

    await expect(
      page.getByText("Die Konfiguration wurde gespeichert."),
    ).toBeVisible();
    const configured = page
      .locator("article.target-card")
      .filter({ hasText: "QA Browser Target" });
    await expect(configured).toBeVisible();
    await expect(
      configured.getByRole("button", { name: "Aktivieren" }),
    ).toBeDisabled();
    await expect(configured).toContainText(/Executor|Berechtigung/i);
    await expectNoManualOperations(page);
  });

  test("zeigt einen echten Optimistic-Locking-Konflikt", async ({ page }) => {
    await login(page);
    await page.goto("/backup-targets");

    const card = page
      .locator("article.target-card")
      .filter({ hasText: "QA Existing Target" });
    await card.getByRole("button", { name: "Bearbeiten" }).click();
    const form = page.getByRole("form", { name: "Backupziel konfigurieren" });

    const sessionResponse = await page.request.get("/api/v1/auth/session");
    expect(sessionResponse.ok()).toBeTruthy();
    const session = (await sessionResponse.json()) as { csrfToken: string };
    const targetsResponse = await page.request.get(
      "/api/v1/backup-targets?limit=100",
    );
    expect(targetsResponse.ok()).toBeTruthy();
    const targetPage = (await targetsResponse.json()) as {
      items: Array<{
        id: string;
        revision: number;
        displayName: string;
        connectionId: string;
        clusterId: string;
        storageId: string;
        minimumFreeBytes: string | null;
        fixedParallelLimit: number | null;
        pbsConnectionId: string | null;
        pbsDatastoreId: string | null;
        pbsNamespaceId: string | null;
        allowedNodes: Array<{ id: string }>;
      }>;
    };
    const target = targetPage.items.find(
      (item) => item.displayName === "QA Existing Target",
    );
    expect(target).toBeDefined();

    const externalUpdate: APIResponse = await page.request.put(
      `/api/v1/backup-targets/${target!.id}`,
      {
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
          "X-CSRF-Token": session.csrfToken,
        },
        data: {
          expectedRevision: target!.revision,
          displayName: target!.displayName,
          connectionId: target!.connectionId,
          clusterId: target!.clusterId,
          storageId: target!.storageId,
          minimumFreeBytes: target!.minimumFreeBytes,
          fixedParallelLimit: target!.fixedParallelLimit,
          pbsConnectionId: target!.pbsConnectionId,
          pbsDatastoreId: target!.pbsDatastoreId,
          pbsNamespaceId: target!.pbsNamespaceId,
          allowedNodeIds: target!.allowedNodes.map((node) => node.id),
        },
      },
    );
    expect(externalUpdate.ok()).toBeTruthy();

    await field(form, "Anzeigename")
      .locator("input")
      .fill("QA Stale Browser Edit");
    await form.getByRole("button", { name: "Backupziel speichern" }).click();

    await expect(
      page.getByText(/Die Konfiguration wurde zwischenzeitlich geändert/),
    ).toBeVisible();
    await expect(page.getByText(/Aktuelle Serverrevision: 2/)).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Aktuellen Serverstand laden" }),
    ).toBeVisible();
  });

  test("erstellt eine Policy und zeigt QEMU-/LXC-Auswahlhierarchie", async ({
    page,
  }) => {
    await login(page);
    await page.goto("/policies");
    await page.getByRole("button", { name: "Policy anlegen" }).click();

    const form = page.getByRole("form", { name: "Policy konfigurieren" });
    await field(form, "Anzeigename").locator("input").fill("QA Browser Policy");
    await selectOption(
      form,
      "Verbindung und Cluster aus Inventar",
      "QA PVE · qa-cluster",
    );
    await selectOption(form, "Konfiguriertes Backupziel", "QA Existing Target");
    await field(form, "Priorität").locator("input").fill("350");
    await selectOption(form, "Backupmodus", "Snapshot");
    await selectOption(form, "Kompression", "Zstandard");
    await field(form, "Maximales Alter in Sekunden")
      .locator("input")
      .fill("86400");
    await field(form, "Letzte behalten").locator("input").fill("5");
    await field(form, "Fehler-E-Mail-Empfänger")
      .locator("textarea")
      .fill("platform@example.test, backup-alerts@example.test");
    await form.getByRole("button", { name: "Policy speichern" }).click();

    await expect(
      page.getByText("Die Konfiguration wurde gespeichert."),
    ).toBeVisible();
    const createdPolicy = page
      .locator("article.policy-card")
      .filter({ hasText: "QA Browser Policy" });
    await expect(createdPolicy).toBeVisible();
    await expect(createdPolicy).toContainText("backup-alerts@example.test");
    await expect(createdPolicy).toContainText("platform@example.test");

    await createdPolicy.getByRole("button", { name: "Bearbeiten" }).click();
    const updateForm = page.getByRole("form", {
      name: "Policy konfigurieren",
    });
    await field(updateForm, "Anzeigename")
      .locator("input")
      .fill("QA Browser Policy Updated");
    await updateForm.getByRole("button", { name: "Policy speichern" }).click();
    await expect(page.getByText("QA Browser Policy Updated")).toBeVisible();

    const existingPolicy = page
      .locator("article.policy-card")
      .filter({ hasText: "QA Existing Policy" });
    await existingPolicy
      .getByRole("button", { name: "Auswahl anzeigen" })
      .click();

    const editor = page.locator(".selection-editor");
    await expect(editor).toContainText("qa-qemu-101");
    await expect(editor).toContainText("Explizit ausgeschlossen");
    await expect(editor).toContainText("qa-lxc-201");
    await expect(editor).toContainText("Guest-Override");

    const selectionForm = page.getByRole("form", {
      name: "Auswahlregel konfigurieren",
    });
    const guestRule = editor
      .locator("article.policy-selection-card")
      .filter({ hasText: "qa-qemu-101" });
    const disableRequest = page.waitForRequest(
      (request) =>
        request.url().endsWith("/selection/disable") &&
        request.method() === "POST",
    );
    await guestRule.getByRole("button", { name: "Regel deaktivieren" }).click();
    const disabledPayload = (await disableRequest).postDataJSON() as {
      entries: Array<{ id: string }>;
    };
    await expect(guestRule).toContainText("Deaktiviert");
    await guestRule.getByRole("button", { name: "Regel reaktivieren" }).click();
    const reactivationRequest = page.waitForRequest(
      (request) =>
        request.url().endsWith("/selection") && request.method() === "PUT",
    );
    await selectionForm
      .getByRole("button", { name: "Auswahlregel speichern" })
      .click();
    const reactivationPayload = (await reactivationRequest).postDataJSON() as {
      entries: Array<{ id: string }>;
    };
    expect(reactivationPayload.entries[0]?.id).toBe(
      disabledPayload.entries[0]?.id,
    );
    await expect(guestRule).toContainText("Aktiv");

    await selectOption(selectionForm, "Ebene", "Node");
    const nodeMultiSelect = field(selectionForm, "Node aus Inventar").locator(
      ".p-multiselect",
    );
    await expect(nodeMultiSelect).toBeVisible();
    await nodeMultiSelect.click();
    await page.getByRole("option", { name: "qa-node-a", exact: true }).click();
    await page.getByRole("option", { name: "qa-node-b", exact: true }).click();
    await page.keyboard.press("Escape");
    await selectOption(selectionForm, "Entscheidung", "Einschließen");
    const bulkRequest = page.waitForRequest(
      (request) =>
        request.url().includes("/api/v1/policies/") &&
        request.url().endsWith("/selection") &&
        request.method() === "PUT",
    );
    await selectionForm
      .getByRole("button", { name: "Auswahlregel speichern" })
      .click();
    const bulkPayload = (await bulkRequest).postDataJSON() as {
      entries: Array<{ nodeId?: string }>;
    };
    expect(bulkPayload.entries).toHaveLength(2);
    expect(bulkPayload.entries.map((entry) => entry.nodeId)).toEqual(
      expect.arrayContaining([expect.any(String), expect.any(String)]),
    );
    expect(new Set(bulkPayload.entries.map((entry) => entry.nodeId)).size).toBe(
      2,
    );
    await expect(
      page.getByText("Die Konfiguration wurde gespeichert."),
    ).toBeVisible();

    const overrideForm = page.getByRole("form", {
      name: "Guest-Override konfigurieren",
    });
    await selectOption(
      overrideForm,
      "Gast aus Inventar",
      "QEMU 101 · qa-qemu-101",
    );
    await selectOption(overrideForm, "Backupmodus", "Snapshot");
    await selectOption(overrideForm, "Kompression", "Zstandard");
    await field(overrideForm, "Letzte behalten").locator("input").fill("4");
    await overrideForm
      .getByRole("button", { name: "Guest-Override speichern" })
      .click();
    await expect(
      page.getByText("Die Konfiguration wurde gespeichert."),
    ).toBeVisible();
    await expectNoManualOperations(page);
  });
});
