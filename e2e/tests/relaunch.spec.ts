import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, resetButton, confirmModal } from '../helpers/metrics';
import { findRow } from '../helpers/db';

/**
 * launch_manager::launch() calls registration::get_or_create() before it
 * resolves any AU content, so hitting launch.php is enough to exercise the
 * "reuse vs recreate" behaviour — the AU package can be missing.
 *
 *   after Delete -> relaunch mints a NEW registration (new UUID)
 *   after Reset  -> relaunch reuses the existing one (same UUID)
 */
async function relaunchAs(page: Page, username: string, cmId: number, auId: number): Promise<void> {
  await page.context().clearCookies(); // drop the teacher session
  await loginAs(page, username, SEED_USER_PASSWORD);
  // A GET is enough; the registration/session are written server-side before
  // the (missing) content URL is resolved, so the response itself doesn't matter.
  await page.request.get(`/mod/cmi5/launch.php?id=${cmId}&auid=${auId}`);
}

test.describe('relaunch after Delete / Reset', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Relaunch', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' }, // deleted
        { username: 'e2e_student2', role: 'student' }, // reset
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Relaunch', au: 'AU 1', completed: true, passed: true, score: 0.6 },
        { user: 'e2e_student2', activity: 'Relaunch', au: 'AU 1', completed: true, passed: true, score: 0.6 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('Delete then relaunch mints a new registration + UUID', async ({ page }) => {
    const activity = seeded.activities[0];
    const auId = activity.aus[0].id;
    const s1 = seeded.progress.find((p) => p.user === 'e2e_student1')!;
    const s1Id = userId(seeded, 'e2e_student1');

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, activity.cmId);
    await deleteButton(page, s1Id).click();
    await confirmModal(page, 'Delete');
    await expect(page.locator('.alert-success')).toBeVisible();

    await relaunchAs(page, 'e2e_student1', activity.cmId, auId);

    const reg = await findRow<{ id: string; registrationid: string }>('cmi5_registrations', {
      cmi5id: activity.cmi5Id,
      userid: s1Id,
    });
    expect(reg).not.toBeNull();
    expect(Number(reg!.id)).not.toBe(s1.registrationId); // a brand-new row
    expect(reg!.registrationid).not.toBe(s1.registrationUuid); // a brand-new UUID
  });

  test('Reset then relaunch reuses the same registration + UUID', async ({ page }) => {
    const activity = seeded.activities[0];
    const auId = activity.aus[0].id;
    const s2 = seeded.progress.find((p) => p.user === 'e2e_student2')!;
    const s2Id = userId(seeded, 'e2e_student2');

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, activity.cmId);
    await resetButton(page, s2Id).click();
    await confirmModal(page, 'Reset');
    await expect(page.locator('.alert-success')).toBeVisible();

    await relaunchAs(page, 'e2e_student2', activity.cmId, auId);

    const reg = await findRow<{ id: string; registrationid: string }>('cmi5_registrations', {
      cmi5id: activity.cmi5Id,
      userid: s2Id,
    });
    expect(Number(reg!.id)).toBe(s2.registrationId); // same row
    expect(reg!.registrationid).toBe(s2.registrationUuid); // same UUID
  });
});
