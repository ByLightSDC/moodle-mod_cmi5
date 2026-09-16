import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, resetButton, confirmModal } from '../helpers/metrics';
import { count } from '../helpers/db';

/**
 * Every reset / delete fires a Moodle event so the action is in the site log.
 * The standard logstore may write on request shutdown, so poll.
 */
test.describe('audit events land in the site log', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Event Check', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Event Check', au: 'AU 1', completed: true, passed: true, score: 0.7 },
        { user: 'e2e_student2', activity: 'Event Check', au: 'AU 1', completed: true, passed: true, score: 0.7 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('delete fires registration_deleted', async ({ page }) => {
    const s1Id = userId(seeded, 'e2e_student1');
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, seeded.activities[0].cmId);
    await deleteButton(page, s1Id).click();
    await confirmModal(page, 'Delete');
    await expect(page.locator('.alert-success')).toBeVisible();

    await expect
      .poll(() =>
        count('logstore_standard_log', {
          eventname: '\\mod_cmi5\\event\\registration_deleted',
          courseid: seeded.courseId,
          relateduserid: s1Id,
        }),
      )
      .toBeGreaterThan(0);
  });

  test('reset fires registration_reset', async ({ page }) => {
    const s2Id = userId(seeded, 'e2e_student2');
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, seeded.activities[0].cmId);
    await resetButton(page, s2Id).click();
    await confirmModal(page, 'Reset');
    await expect(page.locator('.alert-success')).toBeVisible();

    await expect
      .poll(() =>
        count('logstore_standard_log', {
          eventname: '\\mod_cmi5\\event\\registration_reset',
          courseid: seeded.courseId,
          relateduserid: s2Id,
        }),
      )
      .toBeGreaterThan(0);
  });
});
