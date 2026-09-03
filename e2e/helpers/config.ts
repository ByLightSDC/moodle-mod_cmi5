/**
 * Central config for the e2e suite. Reads from process.env (populated from
 * e2e/.env by playwright.config.ts). Fails fast with a clear message if a
 * value with no safe default is missing.
 */

function required(name: string): string {
  const v = process.env[name];
  if (!v) {
    throw new Error(
      `Missing environment variable ${name}. Copy e2e/.env.example to e2e/.env and fill it in.`,
    );
  }
  return v;
}

export const config = {
  admin: {
    username: process.env.E2E_ADMIN_USER ?? 'admin',
    password: required('E2E_ADMIN_PASSWORD'),
  },
  db: {
    host: process.env.E2E_DB_HOST ?? '127.0.0.1',
    port: Number(process.env.E2E_DB_PORT ?? '15432'),
    database: process.env.E2E_DB_NAME ?? 'moodle',
    user: process.env.E2E_DB_USER ?? 'moodle',
    password: process.env.E2E_DB_PASSWORD ?? 'm@0dl3ing',
    // Moodle table prefix, e.g. m_cmi5_registrations.
    prefix: process.env.E2E_DB_PREFIX ?? 'm_',
  },
};
