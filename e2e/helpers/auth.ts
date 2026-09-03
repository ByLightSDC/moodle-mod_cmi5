import { Page } from '@playwright/test';

/**
 * Log in to Moodle through the normal login form.
 *
 * @param page      the Playwright page (from the test's fixtures)
 * @param username  Moodle username
 * @param password  Moodle password
 *
 * Throws with a readable message if the login is rejected.
 */
export async function loginAs(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/login/index.php');

  // The Moodle login page finishes wiring up the password field (the
  // show/hide-password widget) via an async template load AFTER 'load'.
  // Filling + submitting before that settles makes the submitted password
  // get lost and Moodle rejects the login. Wait for the network to quiet down.
  await page.waitForLoadState('networkidle');

  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);

  // Belt and braces: confirm the value actually stuck before we submit.
  await page.locator('#password').evaluate((el, expected) => {
    if ((el as HTMLInputElement).value !== expected) {
      (el as HTMLInputElement).value = expected;
    }
  }, password);

  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForLoadState('load').catch(() => {});

  if (page.url().includes('/login/index.php')) {
    const err = await page
      .locator('#loginerrormessage, [role="alert"]')
      .first()
      .textContent()
      .catch(() => null);
    throw new Error(`Login failed for "${username}": ${err?.trim() ?? 'still on /login/index.php'}`);
  }
}
