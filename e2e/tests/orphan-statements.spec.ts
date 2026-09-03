import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, resetButton, confirmModal } from '../helpers/metrics';
import { count } from '../helpers/db';

/**
 * The per-registration cascade deletes statements by sessionid. A statement
 * tagged with the registration UUID but sessionid 0 (arrived before a session
 * existed, or left dangling by an earlier partial cleanup) isn't reachable that
 * way — purge_state() also deletes cmi5_statements by registration UUID to
 * catch it. The seeder's `detached` option creates exactly this case.
 */
test.describe('orphaned statements (sessionid 0)', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Orphan Check', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Orphan Check', au: 'AU 1', completed: true, passed: true, score: 0.7, detached: 2 },
        { user: 'e2e_student2', activity: 'Orphan Check', au: 'AU 1', completed: true, passed: true, score: 0.7, detached: 2 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('Delete also removes detached statements', async ({ page }) => {
    const s1 = seeded.progress.find((p) => p.user === 'e2e_student1')!;
    const s1Id = userId(seeded, 'e2e_student1');

    // Before: 2 session statements + 2 detached, and 2 of the four have sessionid 0.
    expect(await count('cmi5_statements', { registration: s1.registrationUuid })).toBe(4);
    expect(await count('cmi5_statements', { registration: s1.registrationUuid, sessionid: 0 })).toBe(2);

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, seeded.activities[0].cmId);
    await deleteButton(page, s1Id).click();
    await confirmModal(page, 'Delete');
    await expect(page.locator('.alert-success')).toBeVisible();

    // After: nothing left for this registration, sessionid-0 rows included.
    expect(await count('cmi5_statements', { registration: s1.registrationUuid })).toBe(0);
  });

  test('Reset also removes detached statements', async ({ page }) => {
    const s2 = seeded.progress.find((p) => p.user === 'e2e_student2')!;
    const s2Id = userId(seeded, 'e2e_student2');

    expect(await count('cmi5_statements', { registration: s2.registrationUuid })).toBe(4);

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, seeded.activities[0].cmId);
    await resetButton(page, s2Id).click();
    await confirmModal(page, 'Reset');
    await expect(page.locator('.alert-success')).toBeVisible();

    expect(await count('cmi5_statements', { registration: s2.registrationUuid })).toBe(0);
  });
});
