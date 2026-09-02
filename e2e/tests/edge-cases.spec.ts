import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { callWs } from '../helpers/ws';
import { count } from '../helpers/db';

test.describe('reset/delete web services - edge cases', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Edge', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' }, // has progress -> a registration
        { username: 'e2e_student2', role: 'student' }, // enrolled, no progress -> no registration
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Edge', au: 'AU 1', completed: true, passed: true, score: 0.5 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('bulk action with an empty selection processes nothing, no error', async ({ page }) => {
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    const r = await callWs(page, 'mod_cmi5_bulk_registration_action', {
      cmid: seeded.activities[0].cmId,
      userids: [],
      action: 'delete',
    });
    expect(r.error).toBeFalsy();
    expect(r.data.requested).toBe(0);
    expect(r.data.processed).toBe(0);
  });

  test('bulk action with an unrecognised action is rejected', async ({ page }) => {
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    const r = await callWs(page, 'mod_cmi5_bulk_registration_action', {
      cmid: seeded.activities[0].cmId,
      userids: [userId(seeded, 'e2e_student1')],
      action: 'wipe',
    });
    expect(r.error).toBe(true);
  });

  test('single delete on a learner with no registration is rejected', async ({ page }) => {
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    const r = await callWs(page, 'mod_cmi5_delete_registration', {
      cmid: seeded.activities[0].cmId,
      userid: userId(seeded, 'e2e_student2'), // never had progress
    });
    expect(r.error).toBe(true);
  });

  test('bulk delete reports notfound for missing registrations and is idempotent', async ({ page }) => {
    const cmid = seeded.activities[0].cmId;
    const cmi5Id = seeded.activities[0].cmi5Id;
    const s1 = userId(seeded, 'e2e_student1'); // has a registration
    const s2 = userId(seeded, 'e2e_student2'); // has none

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);

    // First call: s1 deleted, s2 reported notfound, only 1 processed.
    const first = await callWs(page, 'mod_cmi5_bulk_registration_action', {
      cmid,
      userids: [s1, s2],
      action: 'delete',
    });
    expect(first.error).toBeFalsy();
    expect(first.data.processed).toBe(1);
    const status = Object.fromEntries(first.data.results.map((x: any) => [x.userid, x.status]));
    expect(status[s1]).toBe('delete');
    expect(status[s2]).toBe('notfound');
    expect(await count('cmi5_registrations', { cmi5id: cmi5Id, userid: s1 })).toBe(0);

    // Second call with the same users: nothing left to do, still no error.
    const second = await callWs(page, 'mod_cmi5_bulk_registration_action', {
      cmid,
      userids: [s1, s2],
      action: 'delete',
    });
    expect(second.error).toBeFalsy();
    expect(second.data.processed).toBe(0);
    expect(second.data.results.every((x: any) => x.status === 'notfound')).toBe(true);
  });
});
