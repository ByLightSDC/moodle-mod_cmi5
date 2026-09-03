import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, deleteButton, confirmModal } from '../helpers/metrics';
import { count, findRow, enrolmentCount, roleAssignmentCount } from '../helpers/db';

test.describe('per-learner Delete (Metrics > Learners)', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Delete Target', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Delete Target', au: 'AU 1', completed: true, passed: true, score: 0.8 },
        { user: 'e2e_student2', activity: 'Delete Target', au: 'AU 1', completed: true, passed: true, score: 0.6 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('wipes the target learner and leaves the control learner intact', async ({ page }) => {
    const activity = seeded.activities[0];
    const s1 = seeded.progress.find((p) => p.user === 'e2e_student1')!;
    const s2 = seeded.progress.find((p) => p.user === 'e2e_student2')!;
    const s1Id = userId(seeded, 'e2e_student1');
    const s2Id = userId(seeded, 'e2e_student2');

    const gradeItem = await findRow<{ id: number }>('grade_items', {
      itemmodule: 'cmi5',
      iteminstance: activity.cmi5Id,
    });
    const enrolBefore = await enrolmentCount(seeded.courseId);
    const rolesBefore = await roleAssignmentCount(seeded.courseId);

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, activity.cmId);

    // Both learners are listed, both have Delete buttons (teacher has managecontent).
    await expect(deleteButton(page, s1Id)).toBeVisible();
    await expect(deleteButton(page, s2Id)).toBeVisible();

    // Delete student1, confirm the modal.
    await deleteButton(page, s1Id).click();
    await expect(page.getByRole('dialog')).toContainText(/delete all progress/i);
    await confirmModal(page, 'Delete');

    // UI: the optimistic row removal + success toast.
    await expect(deleteButton(page, s1Id)).toHaveCount(0);
    await expect(deleteButton(page, s2Id)).toBeVisible();
    await expect(page.locator('.alert-success')).toContainText(/deleted all progress/i);

    // UI: reload the tab from scratch — student1 is genuinely absent from the
    // server-rendered list, not just removed client-side.
    await openLearnersTab(page, activity.cmId);
    await expect(deleteButton(page, s1Id)).toHaveCount(0);
    await expect(deleteButton(page, s2Id)).toBeVisible();

    // DB: every cmi5 row for student1's registration is gone.
    expect(await count('cmi5_registrations', { id: s1.registrationId })).toBe(0);
    expect(await count('cmi5_sessions', { registrationid: s1.registrationId })).toBe(0);
    expect(await count('cmi5_statements', { registration: s1.registrationUuid })).toBe(0);
    expect(await count('cmi5_au_status', { registrationid: s1.registrationId })).toBe(0);
    expect(await count('cmi5_state_documents', { registrationid: s1.registrationId })).toBe(0);

    // DB: student1's gradebook entry is cleared.
    const g1 = await findRow<{ finalgrade: string | null }>('grade_grades', {
      itemid: gradeItem!.id,
      userid: s1Id,
    });
    expect(g1 === null || g1.finalgrade === null).toBe(true);

    // DB: student2 (control) is completely untouched.
    expect(await count('cmi5_registrations', { id: s2.registrationId })).toBe(1);
    expect(await count('cmi5_au_status', { registrationid: s2.registrationId })).toBe(1);
    const g2 = await findRow<{ finalgrade: string }>('grade_grades', {
      itemid: gradeItem!.id,
      userid: s2Id,
    });
    expect(Number(g2!.finalgrade)).toBeCloseTo(60);

    // DB: no collateral — enrolments and role assignments are unchanged.
    expect(await enrolmentCount(seeded.courseId)).toBe(enrolBefore);
    expect(await roleAssignmentCount(seeded.courseId)).toBe(rolesBefore);
  });
});
