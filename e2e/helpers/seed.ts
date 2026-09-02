import { spawnSync } from 'child_process';
import { readFileSync } from 'fs';
import { randomBytes } from 'crypto';
import path from 'path';

/**
 * Fixture layer: create / destroy a Moodle course + cmi5 activities + learners
 * with progress, by running e2e/fixtures/seed.php inside the webserver
 * container. The PHP script is fed to the container's `php` on stdin, and the
 * spec is passed as an env var; nothing is copied into the container.
 */

const CONTAINER = process.env.E2E_MOODLE_CONTAINER ?? 'moodle-docker-webserver-1';
const SEED_PHP = path.resolve(__dirname, '../fixtures/seed.php');

export type Role = 'editingteacher' | 'teacher' | 'student';

export interface SeedSpec {
  /** Optional; a unique one is generated if omitted. */
  runId?: string;
  activities: Array<{ name: string; aus?: Array<{ title: string }> }>;
  users: Array<{ username: string; role: Role }>;
  progress?: Array<{
    user: string; // username
    activity: string; // activity name
    au: string; // AU title
    completed?: boolean;
    passed?: boolean;
    score?: number; // 0..1, becomes score_scaled -> gradebook
  }>;
}

export interface SeededActivity {
  name: string;
  cmi5Id: number;
  cmId: number;
  contextId: number;
  aus: Array<{ id: number; title: string; auid: string }>;
}
export interface SeededProgress {
  user: string;
  activity: string;
  au: string;
  registrationId: number;
  registrationUuid: string;
  sessionId: number;
  auStatusId: number;
}
export interface SeedResult {
  ok: true;
  runId: string;
  categoryId: number;
  courseId: number;
  activities: SeededActivity[];
  users: Array<{ username: string; userId: number; role: string }>;
  progress: SeededProgress[];
}

function runSeeder(spec: object): SeedResult {
  const res = spawnSync(
    'docker',
    ['exec', '-i', '-e', `E2E_SEED_SPEC=${JSON.stringify(spec)}`, CONTAINER, 'php', '/dev/stdin'],
    { input: readFileSync(SEED_PHP), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 },
  );
  if (res.error) {
    throw new Error(`could not run docker exec: ${res.error.message}`);
  }
  if (res.status !== 0) {
    throw new Error(`seed.php exited ${res.status}\n--- stderr ---\n${res.stderr}\n--- stdout ---\n${res.stdout}`);
  }
  try {
    return JSON.parse(res.stdout) as SeedResult;
  } catch {
    throw new Error(`seed.php did not print JSON\n--- stdout ---\n${res.stdout}\n--- stderr ---\n${res.stderr}`);
  }
}

/** Create the scenario. Returns the ids of everything it made. */
export function seedScenario(spec: SeedSpec): SeedResult {
  const runId = spec.runId ?? `run${Date.now()}${randomBytes(2).toString('hex')}`;
  return runSeeder({ ...spec, runId });
}

/** Delete a scenario's course + category (and all its cmi5 data). Users are kept. */
export function teardownScenario(runId: string): void {
  runSeeder({ teardown: runId });
}

/** Look up a seeded activity by its name. */
export function activityByName(result: SeedResult, name: string): SeededActivity {
  const a = result.activities.find((x) => x.name === name);
  if (!a) {
    throw new Error(`no seeded activity named "${name}"`);
  }
  return a;
}

/** Look up a seeded user's id by username. */
export function userId(result: SeedResult, username: string): number {
  const u = result.users.find((x) => x.username === username);
  if (!u) {
    throw new Error(`no seeded user "${username}"`);
  }
  return u.userId;
}
