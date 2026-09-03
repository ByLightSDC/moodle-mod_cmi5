import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

// Load e2e/.env (copy from .env.example) so specs and helpers can read
// connection details from process.env.
dotenv.config({ path: path.resolve(__dirname, '.env') });

export default defineConfig({
  // Where the spec files live.
  testDir: './tests',

  // Fail the build if someone left test.only in a spec.
  forbidOnly: !!process.env.CI,

  // These tests mutate a single shared Moodle database, so they must NOT run
  // in parallel yet. One worker, one spec at a time. We can add per-worker
  // isolation later if the suite gets slow.
  fullyParallel: false,
  workers: 1,

  // Retry once in CI (a flaky Moodle page load shouldn't fail the run);
  // never retry locally so you see failures immediately.
  retries: process.env.CI ? 1 : 0,

  // Per-test time budget. Moodle pages can be slow; 60s is comfortable.
  timeout: 60_000,

  // Time budget for a single expect() assertion.
  expect: { timeout: 10_000 },

  // The HTML report is written to playwright-report/ ; open it with `npm run report`.
  reporter: [['html', { open: 'never' }], ['list']],

  use: {
    // Every page.goto('/x') is resolved against this.
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8000',

    // Capture a trace (DOM snapshots + network + console) when a test fails,
    // so you can open it in the trace viewer and step through what happened.
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
