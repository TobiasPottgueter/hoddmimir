import { readFileSync } from "node:fs";

import { expect, type Locator, type Page } from "@playwright/test";

export const QA_ADMIN = "qa-admin";
export const QA_VIEWER = "qa-viewer";

function qaPassword(): string {
  const passwordFile =
    process.env.QA_ADMIN_PASSWORD_FILE ?? "/run/secrets/qa_admin_password";

  return readFileSync(passwordFile, "utf8").trim();
}

export async function login(page: Page, username = QA_ADMIN): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Benutzername").fill(username);
  await page.locator('input[type="password"]').fill(qaPassword());
  await page.getByRole("button", { name: "Anmelden" }).click();
  await expect(page).toHaveURL(/\/$/);
}

export function field(form: Locator, label: string): Locator {
  return form.locator("label").filter({ hasText: label });
}

export async function selectOption(
  form: Locator,
  label: string,
  option: string,
): Promise<void> {
  await field(form, label).getByRole("combobox").click();
  await form.page().getByRole("option", { name: option, exact: true }).click();
}

export async function expectNoManualOperations(page: Page): Promise<void> {
  await expect(page.getByText("Jetzt scannen", { exact: true })).toHaveCount(0);
  await expect(page.getByText("Backup starten", { exact: true })).toHaveCount(
    0,
  );
}
