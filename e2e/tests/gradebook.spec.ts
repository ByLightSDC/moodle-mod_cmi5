import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, confirmModal } from '../helpers/metrics';

/** Open one learner's grade report and return the row for the given activity. */
async function gradeRow(page: Page, courseId: number, uid: number, activityName: string) {
  await page.goto(`/grade/report/user/index.php?id=${courseId}&userid=${uid}`);
  return page.getByRole('row').filter({ hasText: activityName });
}

test.describe('grader report reflects a cleared grade', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Gradebook Target', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Gradebook Target', au: 'AU 1', completed: true, passed: true, score: 0.8 },
        { user: 'e2e_student2', activity: 'Gradebook Target', au: 'AU 1', completed: true, passed: true, score: 0.55 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('deleting a learner blanks their grade in the report; the other keeps theirs', async ({ page }) => {
    const activity = seeded.activities[0];
    const s1Id = userId(seeded, 'e2e_student1');
    const s2Id = userId(seeded, 'e2e_student2');

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);

    // Before: each learner's cmi5 grade shows in their user grade report.
    await expect(await gradeRow(page, seeded.courseId, s1Id, activity.name)).toContainText('80.00');
    await expect(await gradeRow(page, seeded.courseId, s2Id, activity.name)).toContainText('55.00');

    // Delete student1 via the Learners table.
    await openLearnersTab(page, activity.cmId);
    await deleteButton(page, s1Id).click();
    await confirmModal(page, 'Delete');
    await expect(page.locator('.alert-success')).toBeVisible();

    // After: student1's cmi5 grade is gone (row still there, no value); student2 unchanged.
    const s1Row = await gradeRow(page, seeded.courseId, s1Id, activity.name);
    await expect(s1Row).toBeVisible();
    await expect(s1Row).not.toContainText('80.00');
    await expect(await gradeRow(page, seeded.courseId, s2Id, activity.name)).toContainText('55.00');
  });
});
