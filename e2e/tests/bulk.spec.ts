import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import {
  seedScenario, teardownScenario, userId, activityByName,
  SEED_USER_PASSWORD, type SeedResult,
} from '../helpers/seed';
import { openLearnersTab, confirmModal } from '../helpers/metrics';
import { count, findRow, enrolmentCount, roleAssignmentCount } from '../helpers/db';

/** Seed one activity with a teacher and three progressed students. */
function seedThree(activityName: string): SeedResult {
  return seedScenario({
    activities: [{ name: activityName, aus: [{ title: 'AU 1' }] }],
    users: [
      { username: 'e2e_teacher', role: 'editingteacher' },
      { username: 'e2e_student1', role: 'student' },
      { username: 'e2e_student2', role: 'student' },
      { username: 'e2e_student3', role: 'student' },
    ],
    progress: [
      { user: 'e2e_student1', activity: activityName, au: 'AU 1', completed: true, passed: true, score: 0.8 },
      { user: 'e2e_student2', activity: activityName, au: 'AU 1', completed: true, passed: true, score: 0.6 },
      { user: 'e2e_student3', activity: activityName, au: 'AU 1', completed: true, passed: true, score: 0.9 },
    ],
  });
}

const rowCheckbox = (page: import('@playwright/test').Page, uid: number) =>
  page.locator(`.mod-cmi5-sel[value="${uid}"]`);

test.describe('bulk Reset / Delete (Metrics > Learners)', () => {
  test('select-all, indeterminate state, and bulk bar visibility', async ({ page }) => {
    const seeded = seedThree('Bulk UI');
    try {
      await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
      await openLearnersTab(page, seeded.activities[0].cmId);

      const bar = page.locator('.mod-cmi5-bulk-bar');
      const selAll = page.locator('.mod-cmi5-sel-all');
      const boxes = page.locator('.mod-cmi5-sel');
      const indeterminate = () => selAll.evaluate((el: HTMLInputElement) => el.indeterminate);

      await expect(boxes).toHaveCount(3);
      await expect(bar).toBeHidden();

      // One row: bar appears, count = 1, select-all goes indeterminate.
      await boxes.nth(0).check();
      await expect(bar).toBeVisible();
      await expect(page.locator('.mod-cmi5-bulk-count')).toHaveText('1 selected');
      await expect(selAll).not.toBeChecked();
      expect(await indeterminate()).toBe(true);

      // Select-all: everything checked, count = 3, not indeterminate.
      await selAll.check();
      await expect(boxes.nth(1)).toBeChecked();
      await expect(boxes.nth(2)).toBeChecked();
      await expect(page.locator('.mod-cmi5-bulk-count')).toHaveText('3 selected');
      expect(await indeterminate()).toBe(false);

      // Clear: bar hides again.
      await selAll.uncheck();
      await expect(bar).toBeHidden();
    } finally {
      teardownScenario(seeded.runId);
    }
  });

  test('ticking a row checkbox does not open the drill-down', async ({ page }) => {
    const seeded = seedThree('Bulk Drilldown');
    try {
      await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
      await openLearnersTab(page, seeded.activities[0].cmId);

      await page.locator('.mod-cmi5-sel').first().check();

      // The drill-down view has a "Back to all learners" button; the list does not.
      await expect(page.locator('.mod-cmi5-back-btn')).toHaveCount(0);
      await expect(page.locator('.mod-cmi5-sel')).toHaveCount(3);
    } finally {
      teardownScenario(seeded.runId);
    }
  });

  test('Delete selected wipes the chosen learners, control learner intact', async ({ page }) => {
    const seeded = seedThree('Bulk Delete');
    try {
      const activity = seeded.activities[0];
      const [s1, s2, s3] = ['e2e_student1', 'e2e_student2', 'e2e_student3'].map(
        (u) => seeded.progress.find((p) => p.user === u)!,
      );
      const [s1Id, s2Id, s3Id] = ['e2e_student1', 'e2e_student2', 'e2e_student3'].map((u) => userId(seeded, u));
      const gradeItem = await findRow<{ id: number }>('grade_items', {
        itemmodule: 'cmi5', iteminstance: activity.cmi5Id,
      });
      const enrolBefore = await enrolmentCount(seeded.courseId);
      const rolesBefore = await roleAssignmentCount(seeded.courseId);

      await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
      await openLearnersTab(page, activity.cmId);

      await rowCheckbox(page, s1Id).check();
      await rowCheckbox(page, s2Id).check();
      await expect(page.locator('.mod-cmi5-bulk-count')).toHaveText('2 selected');

      await page.locator('.mod-cmi5-bulk-delete').click();
      await expect(page.getByRole('dialog')).toContainText(/delete all progress for 2 selected/i);
      await confirmModal(page, /delete selected/i);

      await expect(page.locator('.alert-success')).toContainText(/deleted all progress for 2 learner/i);

      // Reloaded list: s1 + s2 gone, s3 remains.
      await expect(rowCheckbox(page, s1Id)).toHaveCount(0);
      await expect(rowCheckbox(page, s2Id)).toHaveCount(0);
      await expect(rowCheckbox(page, s3Id)).toBeVisible();

      for (const s of [s1, s2]) {
        expect(await count('cmi5_registrations', { id: s.registrationId })).toBe(0);
        expect(await count('cmi5_au_status', { registrationid: s.registrationId })).toBe(0);
        expect(await count('cmi5_statements', { registration: s.registrationUuid })).toBe(0);
      }
      for (const uid of [s1Id, s2Id]) {
        const g = await findRow<{ finalgrade: string | null }>('grade_grades', {
          itemid: gradeItem!.id, userid: uid,
        });
        expect(g === null || g.finalgrade === null).toBe(true);
      }

      // Control.
      expect(await count('cmi5_registrations', { id: s3.registrationId })).toBe(1);
      const g3 = await findRow<{ finalgrade: string }>('grade_grades', {
        itemid: gradeItem!.id, userid: s3Id,
      });
      expect(Number(g3!.finalgrade)).toBeCloseTo(90);

      // No collateral: enrolments + role assignments unchanged.
      expect(await enrolmentCount(seeded.courseId)).toBe(enrolBefore);
      expect(await roleAssignmentCount(seeded.courseId)).toBe(rolesBefore);
    } finally {
      teardownScenario(seeded.runId);
    }
  });

  test('Reset selected clears the chosen learners but keeps their registrations', async ({ page }) => {
    const seeded = seedThree('Bulk Reset');
    try {
      const activity = seeded.activities[0];
      const [s1, s2, s3] = ['e2e_student1', 'e2e_student2', 'e2e_student3'].map(
        (u) => seeded.progress.find((p) => p.user === u)!,
      );
      const [s1Id, s2Id, s3Id] = ['e2e_student1', 'e2e_student2', 'e2e_student3'].map((u) => userId(seeded, u));
      const gradeItem = await findRow<{ id: number }>('grade_items', {
        itemmodule: 'cmi5', iteminstance: activity.cmi5Id,
      });
      const enrolBefore = await enrolmentCount(seeded.courseId);
      const rolesBefore = await roleAssignmentCount(seeded.courseId);

      await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
      await openLearnersTab(page, activity.cmId);

      await rowCheckbox(page, s1Id).check();
      await rowCheckbox(page, s2Id).check();
      await page.locator('.mod-cmi5-bulk-reset').click();
      await expect(page.getByRole('dialog')).toContainText(/reset all sessions and progress for 2 selected/i);
      await confirmModal(page, /reset selected/i);
      await expect(page.locator('.alert-success')).toContainText(/reset progress for 2 learner/i);

      for (const s of [s1, s2]) {
        // registration + UUID kept
        const reg = await findRow<{ registrationid: string }>('cmi5_registrations', { id: s.registrationId });
        expect(reg).not.toBeNull();
        expect(reg!.registrationid).toBe(s.registrationUuid);
        // child data cleared
        expect(await count('cmi5_au_status', { registrationid: s.registrationId })).toBe(0);
        expect(await count('cmi5_sessions', { registrationid: s.registrationId })).toBe(0);
      }
      for (const uid of [s1Id, s2Id]) {
        const g = await findRow<{ finalgrade: string | null }>('grade_grades', {
          itemid: gradeItem!.id, userid: uid,
        });
        expect(g === null || g.finalgrade === null).toBe(true);
      }

      // Control untouched.
      expect(await count('cmi5_au_status', { registrationid: s3.registrationId })).toBe(1);
      const g3 = await findRow<{ finalgrade: string }>('grade_grades', {
        itemid: gradeItem!.id, userid: s3Id,
      });
      expect(Number(g3!.finalgrade)).toBeCloseTo(90);

      // No collateral: enrolments + role assignments unchanged.
      expect(await enrolmentCount(seeded.courseId)).toBe(enrolBefore);
      expect(await roleAssignmentCount(seeded.courseId)).toBe(rolesBefore);
    } finally {
      teardownScenario(seeded.runId);
    }
  });

  test('bulk delete on one activity does not touch the learner in another', async ({ page }) => {
    const seeded = seedScenario({
      activities: [
        { name: 'Bulk Iso A', aus: [{ title: 'AU 1' }] },
        { name: 'Bulk Iso B', aus: [{ title: 'AU 1' }] },
      ],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Bulk Iso A', au: 'AU 1', completed: true, passed: true, score: 0.8 },
        { user: 'e2e_student1', activity: 'Bulk Iso B', au: 'AU 1', completed: true, passed: true, score: 0.7 },
      ],
    });
    try {
      const actA = activityByName(seeded, 'Bulk Iso A');
      const actB = activityByName(seeded, 'Bulk Iso B');
      const pA = seeded.progress.find((p) => p.activity === 'Bulk Iso A')!;
      const pB = seeded.progress.find((p) => p.activity === 'Bulk Iso B')!;
      const s1Id = userId(seeded, 'e2e_student1');

      await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
      await openLearnersTab(page, actA.cmId);
      await rowCheckbox(page, s1Id).check();
      await page.locator('.mod-cmi5-bulk-delete').click();
      await confirmModal(page, /delete selected/i);
      await expect(page.locator('.alert-success')).toContainText(/deleted all progress/i);

      // Activity A wiped, activity B untouched.
      expect(await count('cmi5_registrations', { id: pA.registrationId })).toBe(0);
      expect(await count('cmi5_registrations', { id: pB.registrationId })).toBe(1);
      expect(await count('cmi5_au_status', { registrationid: pB.registrationId })).toBe(1);

      const gItemB = await findRow<{ id: number }>('grade_items', {
        itemmodule: 'cmi5', iteminstance: actB.cmi5Id,
      });
      const gB = await findRow<{ finalgrade: string }>('grade_grades', { itemid: gItemB!.id, userid: s1Id });
      expect(Number(gB!.finalgrade)).toBeCloseTo(70);
    } finally {
      teardownScenario(seeded.runId);
    }
  });
});
