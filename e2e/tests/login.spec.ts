import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { config } from '../helpers/config';

test('logs in as admin', async ({ page }) => {
  await loginAs(page, config.admin.username, config.admin.password);

  // /my/ is the logged-in dashboard. If the session didn't take it would
  // redirect back to /login/, so staying on /my/ proves we're authenticated.
  await page.goto('/my/');
  await expect(page).toHaveURL(/\/my\/?(\?.*)?$/);
});
