import { expect, test, type Locator, type Page } from "@playwright/test";

import {
  expectNoManualOperations,
  field,
  login,
  selectOption,
} from "./support";

const QA_PVE_SCAN_SECRET = "qa-pve-scan-secret";
const QA_PVE_BACKUP_SECRET = "qa-pve-backup-secret";
const QA_PBS_SCAN_SECRET = "00000000-0000-0000-0000-000000000000";

async function openWizard(page: Page): Promise<Locator> {
  await page.goto("/connections");
  await page.getByRole("button", { name: "Verbindung anlegen" }).click();
  const dialog = page.getByRole("dialog", {
    name: "Verbindung sicher einrichten",
  });
  await expect(dialog).toBeVisible();
  return dialog;
}

async function fillPveWizard(
  page: Page,
  options: {
    displayName: string;
    host: string;
    scanSecret?: string;
    backupSecret?: string;
    fingerprint?: string;
  },
): Promise<Locator> {
  const dialog = page.getByRole("dialog", {
    name: "Verbindung sicher einrichten",
  });
  await dialog.getByRole("button", { name: "Endpoint konfigurieren" }).click();
  await field(dialog, "Anzeigename").locator("input").fill(options.displayName);
  await field(dialog, "DNS-Name").locator("input").fill(options.host);
  if (options.fingerprint !== undefined) {
    await selectOption(dialog, "TLS-Modus", "SHA-256-Fingerprint");
    await field(dialog, "SHA-256-Fingerprint")
      .locator("input")
      .fill(options.fingerprint);
  }
  await dialog.getByRole("button", { name: "Token eintragen" }).click();
  await field(dialog, "Scanner-Secret")
    .locator('input[type="password"]')
    .fill(options.scanSecret ?? QA_PVE_SCAN_SECRET);
  await field(dialog, "Backup-Secret")
    .locator('input[type="password"]')
    .fill(options.backupSecret ?? QA_PVE_BACKUP_SECRET);
  await dialog.getByRole("button", { name: "Prüfung vorbereiten" }).click();
  return dialog;
}

async function submitPve(
  page: Page,
  options: Parameters<typeof fillPveWizard>[1],
): Promise<Locator> {
  const dialog = await openWizard(page);
  await fillPveWizard(page, options);
  await dialog
    .getByRole("button", { name: "Sicher prüfen und aktivieren" })
    .click();
  return dialog;
}

test.describe("sicheres Proxmox-Onboarding gegen die lokale QA-Grenze", () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test("zeigt den vom regulären Collector bestätigten QA-Inventarstatus in UTC", async ({
    page,
  }) => {
    await page.goto("/connections");
    const card = page
      .locator("article.connection-card")
      .filter({ hasText: "QA PVE" });
    await expect(
      card.getByText("Automatisches Inventar bestätigt"),
    ).toBeVisible();
    await expect(card.getByText("Sicher geprüft (UTC)")).toBeVisible();
    await expect(card.getByText("Inventarstatus geändert (UTC)")).toBeVisible();
    await expect(
      card.getByText("20000000-0000-4000-8000-000000000001"),
    ).toBeVisible();
  });

  test("aktiviert PVE, verwaltet verifizierte Failover-Endpoints und reaktiviert sicher", async ({
    page,
  }) => {
    const dialog = await openWizard(page);
    await expect(
      dialog.getByText("pveum role", { exact: false }).first(),
    ).toBeVisible();
    await fillPveWizard(page, {
      displayName: "QA Onboarding PVE",
      host: "pve-onboarding.qa.invalid",
    });
    const response = page.waitForResponse(
      (value) =>
        value.url().endsWith("/api/v1/connections/onboarding/activate") &&
        value.request().method() === "POST",
    );
    await dialog
      .getByRole("button", { name: "Sicher prüfen und aktivieren" })
      .click();
    expect((await response).status()).toBe(200);

    await expect(
      dialog.getByText("Verbindung aktiviert").first(),
    ).toBeVisible();
    await expect(
      dialog.getByText("Erster automatischer Inventarlauf ausstehend"),
    ).toBeVisible();
    await expect(page.getByText(QA_PVE_SCAN_SECRET)).toHaveCount(0);
    await expect(page.getByText(QA_PVE_BACKUP_SECRET)).toHaveCount(0);
    await expectNoManualOperations(page);
    await dialog
      .getByRole("button", { name: "Onboarding abschließen" })
      .click();
    await expect(dialog).toBeHidden();

    const card = page
      .locator("article.connection-card")
      .filter({ hasText: "QA Onboarding PVE" });
    await expect(
      card.getByText("Erster automatischer Inventarlauf ausstehend"),
    ).toBeVisible();
    await expect(card.getByText("Noch kein automatischer Lauf")).toBeVisible();
    await expect(
      card.getByRole("button", { name: "Endpoint hinzufügen", exact: true }),
    ).toHaveCount(0);
    await expect(
      card.getByRole("button", { name: "Zugangsdaten rotieren", exact: true }),
    ).toHaveCount(0);
    await expect(
      card.getByRole("button", { name: "Aktivieren", exact: true }),
    ).toHaveCount(0);
    await card
      .getByRole("button", { name: "Verifizierten Endpoint hinzufügen" })
      .click();
    const endpointDialog = page.getByRole("dialog", {
      name: "Proxmox-Verbindung sicher prüfen",
    });
    await expect(
      endpointDialog.getByText("Verifizierten Failover-Endpoint hinzufügen"),
    ).toBeVisible();
    await endpointDialog
      .getByRole("button", { name: "Endpoint konfigurieren" })
      .click();
    await field(endpointDialog, "DNS-Name")
      .locator("input")
      .fill("pve-failover.qa.invalid");
    await endpointDialog
      .getByRole("button", { name: "Token eintragen" })
      .click();
    await field(endpointDialog, "Scanner-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_SCAN_SECRET);
    await field(endpointDialog, "Backup-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_BACKUP_SECRET);
    await endpointDialog
      .getByRole("button", { name: "Prüfung vorbereiten" })
      .click();
    await endpointDialog
      .getByRole("button", { name: "Sicher prüfen und Endpoint speichern" })
      .click();
    await expect(
      endpointDialog.getByText("Endpoint sicher geprüft und gespeichert"),
    ).toBeVisible();
    await expect(
      endpointDialog.getByText("automatische Inventarlauf ist ausstehend"),
    ).toBeVisible();
    await endpointDialog
      .getByRole("button", { name: "Endpoint-Einrichtung abschließen" })
      .click();
    await expect(card.getByText("pve-failover.qa.invalid")).toBeVisible();

    const failoverRow = card
      .getByRole("row")
      .filter({ hasText: "pve-failover.qa.invalid" });
    await failoverRow.getByRole("button", { name: "Bearbeiten" }).click();
    const updateDialog = page.getByRole("dialog", {
      name: "Proxmox-Verbindung sicher prüfen",
    });
    await expect(
      updateDialog.getByText("Endpoint sicher ändern"),
    ).toBeVisible();
    await updateDialog
      .getByRole("button", { name: "Endpoint konfigurieren" })
      .click();
    await field(updateDialog, "DNS-Name")
      .locator("input")
      .fill("pve-failover-updated.qa.invalid");
    await updateDialog.getByRole("button", { name: "Token eintragen" }).click();
    await field(updateDialog, "Scanner-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_SCAN_SECRET);
    await field(updateDialog, "Backup-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_BACKUP_SECRET);
    await updateDialog
      .getByRole("button", { name: "Prüfung vorbereiten" })
      .click();
    await updateDialog
      .getByRole("button", { name: "Sicher prüfen und Endpoint speichern" })
      .click();
    await expect(
      updateDialog.getByText("Endpoint sicher geprüft und gespeichert"),
    ).toBeVisible();
    await updateDialog
      .getByRole("button", { name: "Endpoint-Einrichtung abschließen" })
      .click();
    await expect(
      card.getByText("pve-failover-updated.qa.invalid"),
    ).toBeVisible();

    const updatedFailoverRow = card
      .getByRole("row")
      .filter({ hasText: "pve-failover-updated.qa.invalid" });
    await updatedFailoverRow
      .getByRole("button", { name: "Deaktivieren" })
      .click();
    await expect(updatedFailoverRow.getByText("Deaktiviert")).toBeVisible();
    await expect(
      card.locator("header").getByText("Aktiv", { exact: true }),
    ).toBeVisible();

    await card.getByRole("button", { name: "Deaktivieren" }).first().click();
    await expect(
      card.getByRole("button", { name: "Erneut sicher aktivieren" }),
    ).toBeVisible();
    await card
      .getByRole("button", { name: "Erneut sicher aktivieren" })
      .click();
    const reactivationDialog = page.getByRole("dialog", {
      name: "Proxmox-Verbindung sicher prüfen",
    });
    await expect(
      reactivationDialog.getByText("Proxmox-Verbindung erneut sicher prüfen"),
    ).toBeVisible();
    await reactivationDialog
      .getByRole("button", { name: "Endpoint konfigurieren" })
      .click();
    await field(reactivationDialog, "Aktivierten Endpoint auswählen")
      .getByRole("combobox")
      .click();
    await page
      .getByRole("option", { name: /pve-onboarding\.qa\.invalid:8006/ })
      .click();
    await reactivationDialog
      .getByRole("button", { name: "Token eintragen" })
      .click();
    await field(reactivationDialog, "Scanner-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_SCAN_SECRET);
    await field(reactivationDialog, "Backup-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_BACKUP_SECRET);
    await reactivationDialog
      .getByRole("button", { name: "Prüfung vorbereiten" })
      .click();
    await reactivationDialog
      .getByRole("button", { name: "Erneut prüfen und aktivieren" })
      .click();
    await expect(
      reactivationDialog.getByText(
        "Verbindung erneut sicher geprüft und aktiviert",
      ),
    ).toBeVisible();
    await expect(page.getByText(QA_PVE_SCAN_SECRET)).toHaveCount(0);
    await expect(page.getByText(QA_PVE_BACKUP_SECRET)).toHaveCount(0);
  });

  test("aktiviert PBS ausschließlich mit dem Scanner-Token", async ({
    page,
  }) => {
    const dialog = await openWizard(page);
    await selectOption(dialog, "Proxmox-Produkt", "Proxmox Backup Server");
    await dialog
      .getByRole("button", { name: "Endpoint konfigurieren" })
      .click();
    await field(dialog, "Anzeigename")
      .locator("input")
      .fill("QA Onboarding PBS");
    await field(dialog, "DNS-Name")
      .locator("input")
      .fill("pbs-onboarding.qa.invalid");
    await dialog.getByRole("button", { name: "Token eintragen" }).click();
    await expect(field(dialog, "Backup-Secret")).toHaveCount(0);
    await field(dialog, "Scanner-Secret")
      .locator('input[type="password"]')
      .fill(QA_PBS_SCAN_SECRET);
    await dialog.getByRole("button", { name: "Prüfung vorbereiten" }).click();
    const request = page.waitForRequest(
      (value) =>
        value.url().endsWith("/api/v1/connections/onboarding/activate") &&
        value.method() === "POST",
    );
    await dialog
      .getByRole("button", { name: "Sicher prüfen und aktivieren" })
      .click();
    const body = (await request).postDataJSON() as {
      credentials: { backup?: unknown };
    };
    expect(body.credentials.backup).toBeUndefined();
    await expect(
      dialog.getByText("Erster automatischer Inventarlauf ausstehend"),
    ).toBeVisible();
  });

  test("weist ein falsches Secret zurück", async ({ page }) => {
    const dialog = await submitPve(page, {
      displayName: "QA Wrong Secret",
      host: "pve-onboarding.qa.invalid",
      scanSecret: "definitely-wrong",
    });
    await expect(
      dialog.getByText("Token-Secret wurde abgewiesen", { exact: false }),
    ).toBeVisible();
    await expect(dialog.getByText("Verbindung aktiviert")).toHaveCount(0);
    await expect(page.getByText("definitely-wrong")).toHaveCount(0);
    await dialog.getByRole("button", { name: "Zurück" }).click();
    await expect(
      field(dialog, "Scanner-Secret").locator('input[type="password"]'),
    ).toHaveValue("");
    await expect(
      field(dialog, "Backup-Secret").locator('input[type="password"]'),
    ).toHaveValue("");
  });

  test("weist einen falschen SHA-256-Pin zurück", async ({ page }) => {
    const dialog = await submitPve(page, {
      displayName: "QA Wrong Fingerprint",
      host: "pve-fingerprint-failure.qa.invalid",
      fingerprint: "00".repeat(32),
    });
    await expect(
      dialog.getByText("Fingerprint stimmt nicht überein", { exact: false }),
    ).toBeVisible();
  });

  test("weist ein fehlendes Recht pfadbezogen zurück", async ({ page }) => {
    const dialog = await submitPve(page, {
      displayName: "QA Missing Permission",
      host: "pve-missing-permission.qa.invalid",
    });
    await expect(
      dialog.getByText("erforderliches Recht fehlt", { exact: false }),
    ).toBeVisible();
    await expect(dialog.getByText("/storage", { exact: false })).toBeVisible();
  });

  test("weist ein gefährliches Zusatzrecht pfadbezogen zurück", async ({
    page,
  }) => {
    const dialog = await submitPve(page, {
      displayName: "QA Forbidden Permission",
      host: "pve-forbidden-permission.qa.invalid",
    });
    await expect(
      dialog.getByText("schreibendes oder administratives Zusatzrecht", {
        exact: false,
      }),
    ).toBeVisible();
    await expect(
      dialog.getByText("VM.PowerMgmt", { exact: false }),
    ).toBeVisible();
  });

  test("aktiviert bei sichtbarem zusätzlichem Read-only-Recht", async ({
    page,
  }) => {
    const dialog = await submitPve(page, {
      displayName: "QA Readonly Warning",
      host: "pve-readonly-warning.qa.invalid",
    });
    await expect(
      dialog.getByText("Verbindung aktiviert").first(),
    ).toBeVisible();
    await expect(
      dialog.getByText("Zusätzliche Read-only-Rechte wurden erkannt"),
    ).toBeVisible();
  });

  test("zeigt einen echten Revision-Konflikt bei sicherer Rotation", async ({
    page,
  }) => {
    const activationDialog = await submitPve(page, {
      displayName: "QA Rotation Conflict",
      host: "pve-onboarding.qa.invalid",
    });
    await expect(
      activationDialog.getByText("Verbindung aktiviert").first(),
    ).toBeVisible();
    await activationDialog
      .getByRole("button", { name: "Onboarding abschließen" })
      .click();

    const card = page
      .locator("article.connection-card")
      .filter({ hasText: "QA Rotation Conflict" });
    await card
      .getByRole("button", { name: "Zugangsdaten sicher rotieren" })
      .click();
    const rotationDialog = page.getByRole("dialog", {
      name: "Proxmox-Verbindung sicher prüfen",
    });
    await rotationDialog
      .getByRole("button", { name: "Endpoint konfigurieren" })
      .click();
    await field(rotationDialog, "Aktivierten Endpoint auswählen")
      .getByRole("combobox")
      .click();
    await page
      .getByRole("option", { name: /pve-onboarding\.qa\.invalid:8006/ })
      .click();
    await rotationDialog
      .getByRole("button", { name: "Token eintragen" })
      .click();
    await field(rotationDialog, "Scanner-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_SCAN_SECRET);
    await field(rotationDialog, "Backup-Secret")
      .locator('input[type="password"]')
      .fill(QA_PVE_BACKUP_SECRET);
    await rotationDialog
      .getByRole("button", { name: "Prüfung vorbereiten" })
      .click();

    const session = (await (
      await page.request.get("/api/v1/auth/session")
    ).json()) as { csrfToken: string };
    const list = (await (
      await page.request.get("/api/v1/connections?limit=100")
    ).json()) as {
      items: Array<{ id: string; revision: number; displayName: string }>;
    };
    const connection = list.items.find(
      (value) => value.displayName === "QA Rotation Conflict",
    )!;
    const update = await page.request.put(
      `/api/v1/connections/${connection.id}`,
      {
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
          "X-CSRF-Token": session.csrfToken,
        },
        data: {
          expectedRevision: connection.revision,
          displayName: "QA Rotation Conflict Updated",
        },
      },
    );
    expect(update.ok(), await update.text()).toBeTruthy();

    await rotationDialog
      .getByRole("button", { name: "Sicher prüfen und rotieren" })
      .click();
    await expect(
      rotationDialog.getByText("zwischenzeitlich geändert"),
    ).toBeVisible();
    await expect(
      rotationDialog.getByText(/Aktuelle Revision: 2/),
    ).toBeVisible();
    await expect(
      rotationDialog.getByText("Credentials sicher rotiert"),
    ).toHaveCount(0);
  });
});
