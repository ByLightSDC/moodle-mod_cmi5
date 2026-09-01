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

  // #username / #password / the "Log in" button are Moodle-core identifiers —
  // stable across themes and versions.
  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: /log in/i }).click();

  // On success Moodle redirects off the login page. If we're still there after
  // the wait, the credentials were rejected — read the error and surface it.
  await page
    .waitForURL((url) => !url.pathname.includes('/login/index.php'), { timeout: 15_000 })
    .catch(async () => {
      const err = await page
        .locator('#loginerrormessage, [role="alert"]')
        .first()
        .textContent()
        .catch(() => null);
      throw new Error(`Login failed for "${username}": ${err?.trim() ?? 'still on /login/index.php'}`);
    });
}
