import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, resetButton } from '../helpers/metrics';

/**
 * The Reset/Delete controls (and the bulk checkboxes) require
 * mod/cmi5:managecontent. A user with only mod/cmi5:viewreports can see the
 * Learners report but must not get any way to mutate data — in the UI OR the
 * web service.
 *
 * editingteacher -> has managecontent
 * teacher (non-editing) -> has viewreports, NOT managecontent
 */
test.describe('capability gate on Reset / Delete', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Capability Check', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_teacher_ro', role: 'teacher' },
        { username: 'e2e_student1', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Capability Check', au: 'AU 1', completed: true, passed: true, score: 0.5 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('editing teacher sees the action controls', async ({ page }) => {
    const cmId = seeded.activities[0].cmId;
    const s1Id = userId(seeded, 'e2e_student1');

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, cmId);

    await expect(page.locator('thead th', { hasText: 'Actions' })).toBeVisible();
    await expect(deleteButton(page, s1Id)).toBeVisible();
    await expect(resetButton(page, s1Id)).toBeVisible();
    await expect(page.locator('.mod-cmi5-sel-all')).toBeVisible();
  });

  test('viewreports-only teacher sees the report but no controls', async ({ page }) => {
    const cmId = seeded.activities[0].cmId;
    const s1Id = userId(seeded, 'e2e_student1');

    await loginAs(page, 'e2e_teacher_ro', SEED_USER_PASSWORD);
    await openLearnersTab(page, cmId); // the Learners table still renders

    await expect(page.locator('thead th', { hasText: 'Actions' })).toHaveCount(0);
    await expect(deleteButton(page, s1Id)).toHaveCount(0);
    await expect(resetButton(page, s1Id)).toHaveCount(0);
    await expect(page.locator('.mod-cmi5-sel-all')).toHaveCount(0);
    await expect(page.locator('.mod-cmi5-sel')).toHaveCount(0);
  });

  test('viewreports-only teacher is rejected by the web service', async ({ page }) => {
    const cmId = seeded.activities[0].cmId;
    const s1Id = userId(seeded, 'e2e_student1');

    await loginAs(page, 'e2e_teacher_ro', SEED_USER_PASSWORD);
    await page.goto(`/mod/cmi5/view.php?id=${cmId}`);
    const sesskey = await page.evaluate(() => (window as any).M.cfg.sesskey as string);

    const res = await page.request.post(
      `/lib/ajax/service.php?sesskey=${sesskey}&info=mod_cmi5_delete_registration`,
      { data: [{ index: 0, methodname: 'mod_cmi5_delete_registration', args: { cmid: cmId, userid: s1Id } }] },
    );
    const body = await res.json();
    expect(body[0].error).toBe(true); // capability check rejected it
  });
});
