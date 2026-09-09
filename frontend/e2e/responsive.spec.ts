import { expect, test, type Page } from "@playwright/test";

import { expectNoManualOperations, login } from "./support";

async function expectNoPageOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate<{
    clientWidth: number;
    overflowing: string[];
    scrollWidth: number;
  }>(
    `(() => {
      const clientWidth = document.documentElement.clientWidth;
      const overflowing = [...document.querySelectorAll("body *")]
        .filter((element) => element.getBoundingClientRect().right > clientWidth + 0.5)
        .slice(0, 12)
        .map((element) => {
          const rect = element.getBoundingClientRect();
          return [
            element.tagName.toLowerCase(),
            element.id ? \`#\${element.id}\` : "",
            element.classList.length ? \`.\${[...element.classList].join(".")}\` : "",
            \`right=\${rect.right.toFixed(2)}\`,
            \`width=\${rect.width.toFixed(2)}\`,
          ].join("");
        });
      return {
        clientWidth,
        overflowing,
        scrollWidth: document.documentElement.scrollWidth,
      };
    })()`,
  );

  expect(
    dimensions.scrollWidth,
    `Überbreite Elemente: ${dimensions.overflowing.join(", ")}`,
  ).toBe(dimensions.clientWidth);
}

test("stellt die kritische Navigation auf kleinem Viewport bereit", async ({
  page,
}) => {
  await login(page);
  await page.goto("/policies");

  const toggle = page.locator(".navigation-toggle");
  await expect(toggle).toHaveAccessibleName("Navigation öffnen");
  await expect(toggle).toBeVisible();
  await expect(page.getByRole("navigation")).not.toBeInViewport();
  await toggle.click();
  await expect(toggle).toHaveAttribute("aria-expanded", "true");
  await expect(page.getByRole("link", { name: "Backup-Ziele" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Policies" })).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Administration" }),
  ).toBeVisible();

  await page.getByRole("link", { name: "Backup-Ziele" }).click();
  await expect(
    page.getByRole("heading", { name: "Backup-Ziele", level: 2 }),
  ).toBeVisible();
  await expectNoManualOperations(page);

  const overflow = await page.evaluate<number>(
    "document.documentElement.scrollWidth - window.innerWidth",
  );
  expect(overflow).toBeLessThanOrEqual(1);
});

test("hält Dialoge, Formulare und Laufdetails mobil bedienbar", async ({
  page,
}) => {
  await login(page);

  await page.goto("/connections");
  await page.getByRole("button", { name: "Verbindung anlegen" }).click();
  await expect(
    page.getByRole("dialog", { name: "Verbindung sicher einrichten" }),
  ).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Proxmox-Produkt" }),
  ).toBeVisible();
  expect(
    await page.evaluate<number>(
      "document.documentElement.scrollWidth - window.innerWidth",
    ),
  ).toBeLessThanOrEqual(1);
  await page.keyboard.press("Escape");

  await page.goto("/policies");
  await page.getByRole("button", { name: "Policy anlegen" }).click();
  await expect(
    page.getByRole("form", { name: "Policy konfigurieren" }),
  ).toBeVisible();
  expect(
    await page.evaluate<number>(
      "document.documentElement.scrollWidth - window.innerWidth",
    ),
  ).toBeLessThanOrEqual(1);

  await page.goto("/runs");
  await page.getByText("qa-lxc-201", { exact: true }).first().click();
  await expect(page.getByText("QA sanitized backup log")).toBeVisible();
  expect(
    await page.evaluate<number>(
      "document.documentElement.scrollWidth - window.innerWidth",
    ),
  ).toBeLessThanOrEqual(1);
});

test("hält den sicheren Onboarding-Wizard auf mobilem Viewport bedienbar", async ({
  page,
}) => {
  await login(page);
  await page.goto("/connections");
  await page.getByRole("button", { name: "Verbindung anlegen" }).click();
  const dialog = page.getByRole("dialog", {
    name: "Verbindung sicher einrichten",
  });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.getByText("pveum role list", { exact: false }),
  ).toBeVisible();
  const firstStep = dialog.locator(".onboarding-step");
  const actions = dialog.locator(".onboarding-actions");
  const [stepBox, actionsBox] = await Promise.all([
    firstStep.boundingBox(),
    actions.boundingBox(),
  ]);
  expect(stepBox).not.toBeNull();
  expect(actionsBox).not.toBeNull();
  expect(stepBox!.y + stepBox!.height).toBeLessThanOrEqual(actionsBox!.y + 1);
  const continueBox = await actions
    .getByRole("button", { name: "Endpoint konfigurieren" })
    .boundingBox();
  expect(continueBox).not.toBeNull();
  await page.touchscreen.tap(
    continueBox!.x + continueBox!.width / 2,
    continueBox!.y + continueBox!.height / 2,
  );
  await expect(
    dialog.getByText("Endpoint & TLS", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate<number>(
      "document.documentElement.scrollWidth - window.innerWidth",
    ),
  ).toBeLessThanOrEqual(1);
  await expectNoManualOperations(page);
});

test("begrenzt breite Verbindungs-, Policy- und Laufdetails auf 390 Pixel", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);

  await page.goto("/connections");
  await expect(
    page.getByRole("heading", {
      name: "Verbindungen & Credentials",
      level: 2,
    }),
  ).toBeVisible();
  await expect(page.getByRole("heading", { name: "QA PVE" })).toBeVisible();
  await expectNoPageOverflow(page);

  await page.goto("/policies");
  const policy = page.getByRole("article", { name: "QA Existing Policy" });
  await expect(policy).toBeVisible();
  await expectNoPageOverflow(page);
  await policy.getByRole("button", { name: "Auswahl anzeigen" }).click();
  await expect(
    page.getByRole("heading", {
      name: "Auswahl und Guest-Overrides",
      level: 3,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Auswahlregel speichern" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Guest-Override speichern" }),
  ).toBeVisible();
  await expectNoPageOverflow(page);

  await page.goto("/runs/d0000000-0000-4000-8000-000000000001");
  await expect(page.getByText("QA sanitized backup log")).toBeVisible();
  await expectNoPageOverflow(page);
});
