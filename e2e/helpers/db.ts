import { Pool } from 'pg';
import { config } from './config';

/**
 * A single shared connection pool to the Moodle Postgres database.
 *
 * `allowExitOnIdle` lets the Node process exit when the pool is idle, so we
 * don't need an explicit teardown hook after the test run.
 */
const pool = new Pool({
  host: config.db.host,
  port: config.db.port,
  database: config.db.database,
  user: config.db.user,
  password: config.db.password,
  idleTimeoutMillis: 500,
  allowExitOnIdle: true,
});

/** Prefix a bare table name, e.g. t('cmi5_registrations') -> 'm_cmi5_registrations'. */
export function t(table: string): string {
  return `${config.db.prefix}${table}`;
}

/**
 * Run a parameterised query and return the rows.
 *
 * Always pass VALUES as `params` ($1, $2, …) — never string-concatenate them.
 * Table/column names can't be parameterised in SQL, so the helpers below
 * interpolate them directly; only ever pass literal identifiers you control.
 */
export async function query<T = Record<string, unknown>>(
  sql: string,
  params: unknown[] = [],
): Promise<T[]> {
  const res = await pool.query(sql, params);
  return res.rows as T[];
}

/** COUNT(*) of a table, optionally filtered by equality on the given columns. */
export async function count(
  table: string,
  where: Record<string, unknown> = {},
): Promise<number> {
  const keys = Object.keys(where);
  const clause = keys.length
    ? 'WHERE ' + keys.map((k, i) => `${k} = $${i + 1}`).join(' AND ')
    : '';
  const rows = await query<{ n: number }>(
    `SELECT COUNT(*)::int AS n FROM ${t(table)} ${clause}`,
    keys.map((k) => where[k]),
  );
  return rows[0].n;
}

/** First matching row of a table (equality filter), or null. */
export async function findRow<T = Record<string, unknown>>(
  table: string,
  where: Record<string, unknown>,
): Promise<T | null> {
  const keys = Object.keys(where);
  const clause = keys.map((k, i) => `${k} = $${i + 1}`).join(' AND ');
  const rows = await query<T>(
    `SELECT * FROM ${t(table)} WHERE ${clause} LIMIT 1`,
    keys.map((k) => where[k]),
  );
  return rows[0] ?? null;
}

/** Explicitly close the pool (optional; the process also exits cleanly on its own). */
export async function closePool(): Promise<void> {
  await pool.end();
}
