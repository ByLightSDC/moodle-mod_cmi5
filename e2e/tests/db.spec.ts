import { test, expect } from '@playwright/test';
import { query, count, t } from '../helpers/db';

// These specs use no browser — a Playwright "test" is just an async function;
// it only gets a `page` if you ask for one in the fixtures.

test('connects to the Moodle Postgres database', async () => {
  const rows = await query<{ version: string }>('SELECT version()');
  expect(rows[0].version).toContain('PostgreSQL');
});

test('the mod_cmi5 tables are installed', async () => {
  const rows = await query<{ table_name: string }>(
    `SELECT table_name FROM information_schema.tables WHERE table_name = $1`,
    [t('cmi5_registrations')],
  );
  expect(rows).toHaveLength(1);
});

test('count() helper works against a known table', async () => {
  const users = await count('user');
  expect(users).toBeGreaterThan(0);
});
