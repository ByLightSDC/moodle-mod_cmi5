import { test, expect } from '@playwright/test';

/**
 * Phase 1 smoke test.
 *
 * Proves the harness is wired up: Playwright can reach the Moodle instance
 * named by E2E_BASE_URL and render a page. No plugin logic yet.
 */
test('Moodle login page is reachable', async ({ page }) => {
  await page.goto('/login/index.php');

  // Moodle's login form has a submit button labelled "Log in".
  await expect(page.getByRole('button', { name: /log in/i })).toBeVisible();
});
