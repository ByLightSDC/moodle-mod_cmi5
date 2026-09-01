import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

const ADMIN_USER = process.env.E2E_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'test';

test('logs in as admin', async ({ page }) => {
  await loginAs(page, ADMIN_USER, ADMIN_PASSWORD);

  // /my/ is the logged-in dashboard. If the session didn't take it would
  // redirect back to /login/, so staying on /my/ proves we're authenticated.
  await page.goto('/my/');
  await expect(page).toHaveURL(/\/my\/?(\?.*)?$/);
});
